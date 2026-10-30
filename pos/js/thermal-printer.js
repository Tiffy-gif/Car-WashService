/**
 * PosThermalPrinter - Direct 80mm ESC/POS Thermal Printing Engine
 *
 * Features:
 * - Renders customer receipts and workshop tickets onto high-res 576-dot Canvas
 * - Guarantees pixel-perfect Khmer Unicode & English text rendering
 * - Rasterizes Canvas to ESC/POS 1-bit monochrome bitmap stream (GS v 0)
 * - Automatic paper cutting (GS V 66 3) & Cash Drawer kick pulse (ESC p 0)
 * - Supports Network Printers (LAN / Wi-Fi IP:9100), WebUSB, and Counter Agent
 * - Fallback to standard browser print dialog if printer is unreachable
 */
class PosThermalPrinter {
    static DOT_WIDTH = 576; // Standard 80mm printable width at 203 DPI (72mm = 576 dots)

    // ESC/POS Command Constants
    static CMD = {
        INIT: [0x1B, 0x40],                         // ESC @ (Initialize printer)
        FEED_LINES: (n) => [0x1B, 0x64, n],         // ESC d n (Feed n lines)
        CUT_PAPER: [0x1D, 0x56, 0x42, 0x03],        // GS V 66 3 (Feed 3 lines & cut)
        DRAWER_KICK_PIN2: [0x1B, 0x70, 0x00, 0x19, 0xFA], // ESC p 0 25 250 (50ms pulse on Pin 2)
        DRAWER_KICK_PIN5: [0x1B, 0x70, 0x01, 0x19, 0xFA]  // ESC p 1 25 250 (50ms pulse on Pin 5)
    };

    /**
     * Main Entry: Print Order Receipt Directly
     */
    static async printReceipt(order, options = {}) {
        const isReprint = (typeof options === 'boolean') ? options : !!options.isReprint;
        const config = PosConfig.get();
        if (!config.printerDirectEnabled) {
            // Direct printing disabled, use normal print modal
            this.fallbackToBrowserPrint(order);
            return { success: true, fallback: true };
        }

        try {
            // 1. Draw customer receipt on 576px canvas
            const canvas = await this.renderReceiptToCanvas(order, false, isReprint);
            
            // 2. Convert canvas to ESC/POS binary raster image
            const isCash = (order.payment_method || '').toLowerCase() === 'cash';
            const shouldCut = config.printerAutoCut !== false;
            const shouldKickDrawer = isCash && config.printerOpenDrawer !== false;

            const rasterBytes = this.canvasToEscPos(canvas, {
                cut: shouldCut,
                drawer: shouldKickDrawer
            });

            // 3. Send to thermal printer
            const result = await this.sendPayload(rasterBytes);

            // 4. If workshop copy is turned on, print 2nd ticket for washers/mechanics
            if (config.printerWasherCopy) {
                try {
                    await new Promise(r => setTimeout(r, 450)); // small delay between tickets
                    const wsCanvas = await this.renderReceiptToCanvas(order, true);
                    const wsBytes = this.canvasToEscPos(wsCanvas, { cut: shouldCut, drawer: false });
                    await this.sendPayload(wsBytes);
                } catch (wsErr) {
                    console.warn('Could not print workshop ticket copy:', wsErr);
                }
            }

            return { success: true, ...result };
        } catch (err) {
            console.error('Thermal direct print failed:', err);
            const errMsg = err.message || 'Cannot communicate with thermal printer.';
            
            // Show clear error notice on screen
            if (typeof PosApp !== 'undefined' && PosApp.showPrinterAlert) {
                PosApp.showPrinterAlert(errMsg, order);
            } else {
                alert(`Thermal Printer Notice:\n${errMsg}`);
            }

            // Fallback to normal print window if configured
            if (config.printerFallbackWindow !== false) {
                this.fallbackToBrowserPrint(order);
            }

            return { success: false, error: errMsg };
        }
    }

    /**
     * Print Workshop / Washer Ticket Alone
     */
    static async printWasherTicket(order) {
        return await this.printWorkshopTicket(order);
    }

    static async printWorkshopTicket(order) {
        const config = PosConfig.get();
        try {
            const canvas = await this.renderReceiptToCanvas(order, true);
            const bytes = this.canvasToEscPos(canvas, { cut: config.printerAutoCut !== false, drawer: false });
            return await this.sendPayload(bytes);
        } catch (err) {
            alert(`Cannot print workshop ticket: ${err.message}`);
            return { success: false, error: err.message };
        }
    }

    static KHMER_FONT_STACK = '"Kantumruy Pro", "Noto Sans Khmer", "Khmer UI", "Leelawadee UI", "Khmer OS", sans-serif';

    /**
     * Send Test Print Page to Verify Printer IP, Cable, Cutter & Drawer
     */
    static async testPrint(customConfig = null) {
        if (typeof document !== 'undefined' && document.fonts && document.fonts.ready) {
            try { await document.fonts.ready; } catch (e) {}
        }

        const config = customConfig || PosConfig.get();
        const canvas = document.createElement('canvas');
        canvas.width = this.DOT_WIDTH;
        canvas.height = 540;
        const ctx = canvas.getContext('2d');

        // Fill white
        ctx.fillStyle = '#FFFFFF';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.fillStyle = '#000000';

        // Title & Logo
        ctx.textAlign = 'center';
        ctx.font = `bold 28px ${this.KHMER_FONT_STACK}`;
        ctx.fillText('សាកល្បងម៉ាស៊ីនព្រីន 80mm', canvas.width / 2, 50);
        ctx.font = 'bold 22px "Segoe UI", sans-serif';
        ctx.fillText('THERMAL PRINTER TEST OK', canvas.width / 2, 85);

        this.drawDashedLine(ctx, 110, canvas.width);

        ctx.textAlign = 'left';
        ctx.font = `20px "Segoe UI", ${this.KHMER_FONT_STACK}`;
        ctx.fillText(`Target IP: ${config.printerIp || '192.168.1.200'}:${config.printerPort || 9100}`, 30, 145);
        ctx.fillText(`Connection: ${(config.printerType || 'network').toUpperCase()}`, 30, 180);
        ctx.fillText(`Paper Width: 80mm (576 Dots @ 203 DPI)`, 30, 215);
        ctx.fillText(`Date: ${new Date().toLocaleString()}`, 30, 250);

        this.drawDashedLine(ctx, 280, canvas.width);

        // Khmer test sample
        ctx.textAlign = 'center';
        ctx.font = `bold 22px ${this.KHMER_FONT_STACK}`;
        ctx.fillText('អក្សរខ្មែរច្បាស់ល្អ មិនបែក មិនបាត់ស្រៈ', canvas.width / 2, 320);
        ctx.font = '18px "Segoe UI", sans-serif';
        ctx.fillText('Khmer typography printed via 1-bit raster picture', canvas.width / 2, 350);

        // Status items
        ctx.font = 'bold 20px "Segoe UI", sans-serif';
        ctx.fillText('[OK] Auto-Cutter: Active', canvas.width / 2, 400);
        ctx.fillText('[OK] Cash Drawer Kick: Active', canvas.width / 2, 435);

        this.drawDashedLine(ctx, 465, canvas.width);
        ctx.font = '16px "Segoe UI", sans-serif';
        ctx.fillText('Counter POS Direct Printer Engine', canvas.width / 2, 500);

        const bytes = this.canvasToEscPos(canvas, {
            cut: config.printerAutoCut !== false,
            drawer: config.printerOpenDrawer !== false
        });

        return await this.sendPayload(bytes, config);
    }

    /**
     * Render Complete Receipt to HTML5 Canvas
     * @param {Object} order
     * @param {Boolean} isWorkshop
     * @param {Boolean} isReprint
     */
    static async renderReceiptToCanvas(order, isWorkshop = false, isReprint = false) {
        if (typeof document !== 'undefined' && document.fonts && document.fonts.ready) {
            try { await document.fonts.ready; } catch (e) {}
        }

        const settings = (typeof PosApp !== 'undefined' ? PosApp.getSettings() : {}) || {};
        const width = this.DOT_WIDTH; // 576 dots
        const lang = (typeof I18nManager !== 'undefined') ? I18nManager.getLang() : 'km';

        // Estimate canvas height dynamically
        const items = order.items || [];
        let estHeight = 750 + (items.length * 60);
        if (isReprint) estHeight += 70;
        if (order.status === 'cancelled' || order.status === 'unpaid' || order.payment_status === 'unpaid') estHeight += 60;
        if (order.current_mileage) estHeight += 100;
        if (order.repair_notes) estHeight += 90;
        if (isWorkshop) estHeight = 700 + (items.length * 55);

        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = estHeight;
        const ctx = canvas.getContext('2d');

        // White background
        ctx.fillStyle = '#FFFFFF';
        ctx.fillRect(0, 0, width, estHeight);
        ctx.fillStyle = '#000000';

        let y = 30;

        // ==========================================
        // A. WORKSHOP / WASHER JOB TICKET
        // ==========================================
        if (isWorkshop) {
            ctx.textAlign = 'center';
            ctx.font = `bold 24px ${this.KHMER_FONT_STACK}`;
            const wsTitle = lang === 'km' ? '*** សន្លឹកការងារជាង / ជាងលាង ***' : '*** WORKSHOP / WASHER TICKET ***';
            ctx.fillText(wsTitle, width / 2, y);
            y += 35;

            ctx.font = 'bold 20px "Segoe UI", sans-serif';
            ctx.fillText(`ORDER #${order.order_number || ''}`, width / 2, y);
            y += 28;
            ctx.font = '17px "Segoe UI", sans-serif';
            ctx.fillText(order.created_at || new Date().toLocaleString(), width / 2, y);
            y += 20;

            this.drawDashedLine(ctx, y, width);
            y += 25;

            // Giant License Plate Badge
            ctx.fillStyle = '#000000';
            ctx.fillRect(40, y, width - 80, 75);
            ctx.fillStyle = '#FFFFFF';
            ctx.textAlign = 'center';
            ctx.font = 'bold 42px "Segoe UI", sans-serif';
            ctx.fillText(order.plate_number || 'WALK-IN', width / 2, y + 54);
            ctx.fillStyle = '#000000';
            y += 95;

            // Vehicle Type & Model
            ctx.textAlign = 'left';
            ctx.font = `bold 22px ${this.KHMER_FONT_STACK}`;
            const vtypeStr = this.formatVehicleType(order.vehicle_type);
            const vtypeLabel = lang === 'km' ? 'ប្រភេទយានយន្ត៖' : 'Vehicle:';
            ctx.fillText(`${vtypeLabel} ${vtypeStr}`, 30, y);
            y += 32;

            // Customer
            const custLabel = lang === 'km' ? 'អតិថិជន៖' : 'Customer:';
            ctx.fillText(`${custLabel} ${order.customer_name || 'Walk-in'}`, 30, y);
            y += 30;

            // Assigned Washer / Mechanic
            const catalog = (typeof PosStorage !== 'undefined' ? PosStorage.getCatalog() : {}) || {};
            const washers = catalog.washers || [];
            const mechanics = catalog.mechanics || [];
            
            const washerNames = (order.washers || []).map(id => washers.find(w => w.id == id)?.full_name || `#${id}`).join(', ');
            const mechanicNames = (order.mechanics || []).map(id => mechanics.find(m => m.id == id)?.full_name || `#${id}`).join(', ');

            if (washerNames) {
                const wLabel = lang === 'km' ? 'ជាងលាង៖' : 'Washers:';
                ctx.fillText(`${wLabel} ${washerNames}`, 30, y);
                y += 30;
            }
            if (mechanicNames) {
                const mLabel = lang === 'km' ? 'ជាងជួសជុល៖' : 'Mechanics:';
                ctx.fillText(`${mLabel} ${mechanicNames}`, 30, y);
                y += 30;
            }

            this.drawDashedLine(ctx, y, width);
            y += 25;

            // Services list
            ctx.font = `bold 22px ${this.KHMER_FONT_STACK}`;
            const reqLabel = lang === 'km' ? 'សេវាកម្មត្រូវអនុវត្ត៖' : 'Services Required:';
            ctx.fillText(reqLabel, 30, y);
            y += 32;

            items.forEach((it, idx) => {
                const name = (lang === 'km' && it.name_km) ? it.name_km : (it.name || it.name_en || 'Service');
                ctx.fillText(`${idx + 1}. ${name} (x${it.qty || 1})`, 40, y);
                y += 32;
            });

            // Repair Notes Box
            if (order.repair_notes) {
                y += 10;
                ctx.strokeRect(30, y, width - 60, 65);
                ctx.font = 'bold 20px "Kantumruy Pro", sans-serif';
                const noteLabel = lang === 'km' ? 'ចំណាំ៖' : 'Note:';
                ctx.fillText(`${noteLabel} ${order.repair_notes}`, 45, y + 40);
                y += 85;
            }

            if (order.current_mileage) {
                y += 10;
                ctx.font = `18px ${this.KHMER_FONT_STACK}`;
                const kmLabel = lang === 'km' ? 'គីឡូម៉ែត្រ៖' : 'Mileage:';
                ctx.fillText(`${kmLabel} ${order.current_mileage.toLocaleString()} km`, 30, y);
                y += 25;
            }

            y += 20;
            this.drawDashedLine(ctx, y, width);
            y += 35;
            ctx.textAlign = 'center';
            ctx.font = '16px "Segoe UI", sans-serif';
            ctx.fillText(lang === 'km' ? '-- បញ្ចប់សន្លឹកជាង --' : '-- Workshop Ticket End --', width / 2, y);
            y += 40;

            return this.cropCanvasHeight(canvas, y);
        }

        // ==========================================
        // B. CUSTOMER 80MM RECEIPT
        // ==========================================

        // 1. Logo
        const showLogo = settings.receipt_show_logo !== false && settings.receipt_show_logo !== 0 && settings.receipt_show_logo !== '0';
        const logoDataUri = (typeof PosStorage !== 'undefined' ? PosStorage.getLogoDataUri() : null) || settings.shop_logo_url || settings.logo_url;
        
        if (showLogo && logoDataUri) {
            try {
                const img = await this.loadImage(logoDataUri);
                if (img) {
                    const maxW = 240;
                    const maxH = 85;
                    let imgW = img.width;
                    let imgH = img.height;
                    const scale = Math.min(maxW / imgW, maxH / imgH, 1);
                    imgW *= scale;
                    imgH *= scale;
                    ctx.drawImage(img, (width - imgW) / 2, y, imgW, imgH);
                    y += imgH + 15;
                }
            } catch (e) {
                console.warn('Could not render logo to canvas:', e);
            }
        }

        // 2. Shop Names
        const rcptLang = settings.receipt_language || 'both';
        ctx.textAlign = 'center';

        if (rcptLang !== 'en') {
            ctx.font = `bold 28px ${this.KHMER_FONT_STACK}`;
            ctx.fillText(settings.shop_name_km || 'សេវាកម្មលាងរថយន្ត និងម៉ូតូទំនើប', width / 2, y);
            y += 36;
        }

        if (rcptLang !== 'km') {
            ctx.font = 'bold 22px "Segoe UI", sans-serif';
            ctx.fillText(settings.shop_name_en || 'Car Wash & Detailing', width / 2, y);
            y += 30;
        }

        // 3. Address and Phone
        ctx.font = `18px ${this.KHMER_FONT_STACK}`;
        if (settings.shop_address) {
            ctx.fillText(settings.shop_address, width / 2, y);
            y += 26;
        }
        if (settings.shop_phone) {
            ctx.fillText(`Tel: ${settings.shop_phone}`, width / 2, y);
            y += 28;
        }

        this.drawDashedLine(ctx, y, width);
        y += 25;

        // Banners for Reprint, Cancelled, Unpaid
        const isCancelled = order.status === 'cancelled';
        const isUnpaid = (order.payment_status === 'unpaid' || order.status === 'unpaid') && !isCancelled;

        if (isReprint) {
            ctx.strokeRect(30, y, width - 60, 48);
            ctx.textAlign = 'center';
            ctx.font = `bold 20px "Segoe UI", ${this.KHMER_FONT_STACK}`;
            const reprintTitle = lang === 'km' ? '*** ច្បាប់ចម្លង / បោះពុម្ពឡើងវិញ ***' : '*** COPY / REPRINT ***';
            ctx.fillText(reprintTitle, width / 2, y + 24);
            ctx.font = '14px "Segoe UI", sans-serif';
            ctx.fillText(`${lang === 'km' ? 'បោះពុម្ពឡើងវិញនៅ៖' : 'Reprinted:'} ${new Date().toLocaleString()}`, width / 2, y + 42);
            y += 58;
        }

        if (isCancelled) {
            ctx.fillRect(30, y, width - 60, 40);
            ctx.fillStyle = '#FFFFFF';
            ctx.textAlign = 'center';
            ctx.font = `bold 22px "Segoe UI", ${this.KHMER_FONT_STACK}`;
            ctx.fillText(lang === 'km' ? '*** បានបោះបង់ ***' : '*** CANCELLED ***', width / 2, y + 28);
            ctx.fillStyle = '#000000';
            y += 50;
        } else if (isUnpaid) {
            ctx.strokeRect(30, y, width - 60, 40);
            ctx.textAlign = 'center';
            ctx.font = `bold 22px "Segoe UI", ${this.KHMER_FONT_STACK}`;
            ctx.fillText(lang === 'km' ? '*** មិនទាន់ទូទាត់ ***' : '*** UNPAID ***', width / 2, y + 28);
            y += 50;
        }

        // 4. Invoice Metadata
        ctx.textAlign = 'left';
        ctx.font = `19px "Segoe UI", ${this.KHMER_FONT_STACK}`;
        this.drawTwoColumns(ctx, lang === 'km' ? 'វិក្កយបត្រ៖' : 'Invoice #:', order.order_number || 'ORD-000', 30, width - 30, y);
        y += 28;
        this.drawTwoColumns(ctx, lang === 'km' ? 'កាលបរិច្ឆេទ៖' : 'Date:', order.created_at || new Date().toLocaleString(), 30, width - 30, y);
        y += 28;
        this.drawTwoColumns(ctx, lang === 'km' ? 'បេឡា៖' : 'Cashier:', order.cashier_name || 'Cashier', 30, width - 30, y);
        y += 28;
        this.drawTwoColumns(ctx, lang === 'km' ? 'អតិថិជន៖' : 'Customer:', order.customer_name || 'Walk-in Guest', 30, width - 30, y);
        y += 32;

        // Vehicle Plate Tag
        ctx.fillStyle = '#000000';
        ctx.fillRect(30, y, width - 60, 46);
        ctx.fillStyle = '#FFFFFF';
        ctx.font = 'bold 24px "Segoe UI", sans-serif';
        ctx.textAlign = 'left';
        ctx.fillText(lang === 'km' ? 'ស្លាកលេខ៖' : 'PLATE #:', 45, y + 32);
        ctx.textAlign = 'right';
        ctx.fillText(order.plate_number || 'WALK-IN', width - 45, y + 32);
        ctx.fillStyle = '#000000';
        y += 62;

        this.drawDashedLine(ctx, y, width);
        y += 24;

        // 5. Items Table Header
        ctx.font = `bold 19px "Segoe UI", ${this.KHMER_FONT_STACK}`;
        ctx.textAlign = 'left';
        ctx.fillText(lang === 'km' ? 'ទំនិញ/សេវា' : 'ITEM', 30, y);
        ctx.textAlign = 'center';
        ctx.fillText(lang === 'km' ? 'ចំនួន' : 'QTY', width - 130, y);
        ctx.textAlign = 'right';
        ctx.fillText(lang === 'km' ? 'តម្លៃ' : 'PRICE', width - 30, y);
        y += 14;

        this.drawDashedLine(ctx, y, width);
        y += 26;

        // Items Rows
        ctx.font = `20px ${this.KHMER_FONT_STACK}`;
        items.forEach(it => {
            const name = (lang === 'km' && it.name_km) ? it.name_km : (it.name || it.name_en || 'Item');
            const lineTotal = `$${((it.price_usd || 0) * (it.qty || 1)).toFixed(2)}`;
            ctx.textAlign = 'left';
            ctx.fillText(name, 30, y);
            ctx.textAlign = 'center';
            ctx.fillText(String(it.qty || 1), width - 130, y);
            ctx.textAlign = 'right';
            ctx.fillText(lineTotal, width - 30, y);
            y += 30;
        });

        this.drawDashedLine(ctx, y, width);
        y += 26;

        // 6. Totals
        const subtotal = items.reduce((sum, it) => sum + ((it.price_usd || 0) * (it.qty || 1)), 0);
        this.drawTwoColumns(ctx, lang === 'km' ? 'សរុបបឋម៖' : 'Subtotal:', `$${subtotal.toFixed(2)}`, 30, width - 30, y);
        y += 28;

        if (order.discount_usd > 0) {
            this.drawTwoColumns(ctx, lang === 'km' ? 'បញ្ចុះតម្លៃ៖' : 'Discount:', `-$${parseFloat(order.discount_usd).toFixed(2)}`, 30, width - 30, y);
            y += 28;
        }

        this.drawSolidLine(ctx, y, width);
        y += 32;

        // Big Total Lines
        ctx.font = 'bold 30px "Segoe UI", sans-serif';
        this.drawTwoColumns(ctx, lang === 'km' ? 'សរុប USD៖' : 'TOTAL USD:', `$${parseFloat(order.total_usd || subtotal).toFixed(2)}`, 30, width - 30, y);
        y += 36;

        ctx.font = `bold 24px ${this.KHMER_FONT_STACK}`;
        this.drawTwoColumns(ctx, lang === 'km' ? 'សរុប រៀល៖' : 'TOTAL KHR:', `${parseInt(order.total_khr || 0).toLocaleString()} ៛`, 30, width - 30, y);
        y += 32;

        this.drawSolidLine(ctx, y, width);
        y += 28;

        // 7. Payment Info
        ctx.font = `bold 20px "Segoe UI", ${this.KHMER_FONT_STACK}`;
        let methodStr = (order.payment_method || 'CASH').toUpperCase();
        if (isUnpaid) {
            methodStr = lang === 'km' ? 'មិនទាន់ទូទាត់' : 'UNPAID';
        }
        this.drawTwoColumns(ctx, lang === 'km' ? 'វិធីទូទាត់៖' : 'Payment:', methodStr, 30, width - 30, y);
        y += 28;

        if (!isUnpaid && methodStr === 'CASH') {
            ctx.font = '19px "Segoe UI", sans-serif';
            const tendered = parseFloat(order.amount_tendered_usd || order.total_usd || 0);
            const change = parseFloat(order.change_usd || 0);
            this.drawTwoColumns(ctx, lang === 'km' ? 'ប្រាក់ទទួល៖' : 'Tendered:', `$${tendered.toFixed(2)}`, 30, width - 30, y);
            y += 26;
            this.drawTwoColumns(ctx, lang === 'km' ? 'ប្រាក់អាប់៖' : 'Change:', `$${change.toFixed(2)}`, 30, width - 30, y);
            y += 30;
        } else if (isUnpaid) {
            ctx.font = 'bold 20px "Segoe UI", sans-serif';
            this.drawTwoColumns(ctx, lang === 'km' ? 'ប្រាក់ត្រូវបង់៖' : 'Amount Due:', `$${parseFloat(order.total_usd || 0).toFixed(2)}`, 30, width - 30, y);
            y += 30;
        }

        // 8. Oil Care Reminder Box
        if (order.current_mileage && order.current_mileage > 0) {
            y += 10;
            ctx.strokeRect(30, y, width - 60, 68);
            ctx.textAlign = 'center';
            ctx.font = `bold 18px ${this.KHMER_FONT_STACK}`;
            ctx.fillText('OIL CARE REMINDER / រំលឹកប្តូរប្រេង', width / 2, y + 26);
            ctx.font = '16px "Segoe UI", sans-serif';
            const nextKm = order.next_oil_mileage || (order.current_mileage + 5000);
            const nextDate = order.next_oil_date ? ` (Due: ${order.next_oil_date})` : '';
            ctx.fillText(`Current: ${order.current_mileage.toLocaleString()} km | Next: ${nextKm.toLocaleString()} km${nextDate}`, width / 2, y + 52);
            y += 88;
        }

        // 9. Thank You Footer
        y += 10;
        this.drawDashedLine(ctx, y, width);
        y += 26;

        ctx.textAlign = 'center';
        ctx.font = `20px ${this.KHMER_FONT_STACK}`;
        const footerMsg = settings.receipt_footer || 'Thank you for your visit!\nសូមអរគុណ!';
        const footerLines = footerMsg.split('\n');
        footerLines.forEach(line => {
            ctx.fillText(line.trim(), width / 2, y);
            y += 26;
        });

        ctx.font = '14px "Segoe UI", sans-serif';
        ctx.fillText('Powered by Counter POS', width / 2, y);
        y += 45; // clearance for cutter blade

        return this.cropCanvasHeight(canvas, y);
    }

    /**
     * Convert Canvas to 1-Bit ESC/POS Raster Stream (GS v 0)
     * 576 dots width = 72 bytes per horizontal row
     */
    static canvasToEscPos(canvas, options = {}) {
        const width = canvas.width;
        const height = canvas.height;
        const ctx = canvas.getContext('2d');
        const imgData = ctx.getImageData(0, 0, width, height).data;

        const widthBytes = Math.ceil(width / 8); // 72 bytes
        const bitmapBytes = new Uint8Array(widthBytes * height);

        let byteIdx = 0;
        for (let y = 0; y < height; y++) {
            for (let b = 0; b < widthBytes; b++) {
                let byteVal = 0;
                for (let bit = 0; bit < 8; bit++) {
                    const x = (b * 8) + bit;
                    if (x < width) {
                        const pixelIdx = (y * width + x) * 4;
                        const r = imgData[pixelIdx];
                        const g = imgData[pixelIdx + 1];
                        const bVal = imgData[pixelIdx + 2];
                        const a = imgData[pixelIdx + 3];

                        // Luminance threshold: < 175 = black dot (1), else white (0)
                        const luminance = (0.299 * r) + (0.587 * g) + (0.114 * bVal);
                        if (a > 120 && luminance < 175) {
                            byteVal |= (1 << (7 - bit));
                        }
                    }
                }
                bitmapBytes[byteIdx++] = byteVal;
            }
        }

        // Build ESC/POS Command Byte Array
        const buffer = [];

        // 1. Initialize Printer (ESC @)
        buffer.push(...this.CMD.INIT);

        // 2. Set line spacing to 0 for seamless raster
        buffer.push(0x1B, 0x33, 0x00);

        // 3. Raster Bit Image Command: GS v 0 0 xL xH yL yH [data]
        const xL = widthBytes & 0xFF;
        const xH = (widthBytes >> 8) & 0xFF;
        const yL = height & 0xFF;
        const yH = (height >> 8) & 0xFF;

        buffer.push(0x1D, 0x76, 0x30, 0x00, xL, xH, yL, yH);
        for (let i = 0; i < bitmapBytes.length; i++) {
            buffer.push(bitmapBytes[i]);
        }

        // 4. Feed paper slightly (ESC d 2)
        buffer.push(0x1B, 0x64, 0x02);

        // 5. Open Cash Drawer if applicable (ESC p 0)
        if (options.drawer) {
            buffer.push(...this.CMD.DRAWER_KICK_PIN2);
            buffer.push(...this.CMD.DRAWER_KICK_PIN5);
        }

        // 6. Paper Cut Command (GS V 66 3)
        if (options.cut) {
            buffer.push(...this.CMD.CUT_PAPER);
        }

        return new Uint8Array(buffer);
    }

    /**
     * Dispatch Payload to Printer based on Connection Mode
     */
    static async sendPayload(bytes, customConfig = null) {
        const config = customConfig || PosConfig.get();
        const type = config.printerType || 'network';

        // Base64 encode byte stream
        const base64Data = this.uint8ToBase64(bytes);

        // A. Mode: Network Thermal Printer (TCP socket to port 9100)
        if (type === 'network') {
            const ip = config.printerIp || '192.168.1.200';
            const port = parseInt(config.printerPort) || 9100;

            // Try local project endpoint (api/printer.php) first
            let res = null;
            let lastError = null;

            try {
                const base = (config.backofficeUrl || '').replace(/\/+$/, '');
                const printerEndpoint = base ? `${base}/api/printer.php` : '../api/printer.php';
                res = await fetch(printerEndpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'print', ip, port, data: base64Data })
                });
                if (res.ok) {
                    const json = await res.json();
                    if (json.success) return json;
                    throw new Error(json.error || 'Printer rejected print job');
                }
            } catch (e) {
                lastError = e;
            }

            // Secondary: Try standalone local counter bridge on port 1818 (for cloud POS deployments)
            try {
                const agentUrl = (config.printerAgentUrl || 'http://127.0.0.1:1818').replace(/\/+$/, '') + '/print';
                res = await fetch(agentUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'print', ip, port, data: base64Data })
                });
                if (res.ok) {
                    const json = await res.json();
                    if (json.success) return json;
                    throw new Error(json.error || 'Local print service error');
                }
            } catch (agentErr) {
                // If both fail, throw meaningful error
                throw new Error(`Cannot connect to thermal printer at ${ip}:${port}. Check printer power, paper roll, and LAN network cable.`);
            }

            if (lastError) throw lastError;
        }

        // B. Mode: Direct WebUSB (Zero drivers needed in Chrome / Edge)
        else if (type === 'usb') {
            return await this.sendWebUsb(bytes);
        }

        // C. Mode: Local Agent Bridge
        else if (type === 'agent') {
            const agentUrl = (config.printerAgentUrl || 'http://127.0.0.1:1818').replace(/\/+$/, '') + '/print';
            const res = await fetch(agentUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'print',
                    ip: config.printerIp,
                    port: config.printerPort,
                    data: base64Data
                })
            });
            const json = await res.json();
            if (!res.ok || !json.success) {
                throw new Error(json.error || 'Counter printer bridge rejected job.');
            }
            return json;
        }

        throw new Error(`Unknown printer connection mode: ${type}`);
    }

    /**
     * WebUSB Direct Thermal Printer Driver
     */
    static async sendWebUsb(bytes) {
        if (!navigator.usb) {
            throw new Error('WebUSB is not supported in this browser. Please use Google Chrome or Microsoft Edge.');
        }

        // Look for paired device or prompt user
        let device = null;
        const paired = await navigator.usb.getDevices();
        if (paired.length > 0) {
            device = paired[0];
        } else {
            device = await navigator.usb.requestDevice({ filters: [] });
        }

        if (!device) throw new Error('No USB thermal printer selected.');

        await device.open();
        if (device.configuration === null) {
            await device.selectConfiguration(1);
        }

        // Find standard printer interface & OUT endpoint
        let endpointNum = 1;
        for (const iface of device.configuration.interfaces) {
            for (const alt of iface.alternates) {
                for (const ep of alt.endpoints) {
                    if (ep.direction === 'out') {
                        endpointNum = ep.endpointNumber;
                        await device.claimInterface(iface.interfaceNumber);
                        break;
                    }
                }
            }
        }

        // Transfer bytes in chunks
        const chunkSize = 64;
        for (let i = 0; i < bytes.length; i += chunkSize) {
            const slice = bytes.slice(i, i + chunkSize);
            await device.transferOut(endpointNum, slice);
        }

        return { success: true, mode: 'webusb', deviceName: device.productName };
    }

    /**
     * Fallback to Standard Browser Print Window
     */
    static fallbackToBrowserPrint(order) {
        if (typeof PosApp !== 'undefined' && PosApp.isFullscreen && PosApp.isFullscreen()) {
            PosApp.wasFullscreenPreferred = true;
        }
        if (typeof PosApp !== 'undefined' && PosApp.renderReceipt) {
            PosApp.renderReceipt(order);
            const modal = document.getElementById('modalReceipt');
            if (modal) modal.classList.add('open');
        } else {
            window.print();
        }
    }

    // Helper Graphics Utilities
    static drawDashedLine(ctx, y, width) {
        ctx.save();
        ctx.setLineDash([6, 5]);
        ctx.lineWidth = 1.5;
        ctx.beginPath();
        ctx.moveTo(30, y);
        ctx.lineTo(width - 30, y);
        ctx.stroke();
        ctx.restore();
    }

    static drawSolidLine(ctx, y, width) {
        ctx.save();
        ctx.lineWidth = 2;
        ctx.beginPath();
        ctx.moveTo(30, y);
        ctx.lineTo(width - 30, y);
        ctx.stroke();
        ctx.restore();
    }

    static drawTwoColumns(ctx, leftText, rightText, leftX, rightX, y) {
        ctx.textAlign = 'left';
        ctx.fillText(leftText, leftX, y);
        ctx.textAlign = 'right';
        ctx.fillText(rightText, rightX, y);
    }

    static cropCanvasHeight(canvas, finalHeight) {
        const cropped = document.createElement('canvas');
        cropped.width = canvas.width;
        cropped.height = finalHeight;
        const cCtx = cropped.getContext('2d');
        cCtx.drawImage(canvas, 0, 0, canvas.width, finalHeight, 0, 0, canvas.width, finalHeight);
        return cropped;
    }

    static loadImage(src) {
        return new Promise((resolve) => {
            const img = new Image();
            img.crossOrigin = 'Anonymous';
            img.onload = () => resolve(img);
            img.onerror = () => resolve(null);
            img.src = src;
        });
    }

    static uint8ToBase64(bytes) {
        let binary = '';
        const len = bytes.byteLength;
        for (let i = 0; i < len; i++) {
            binary += String.fromCharCode(bytes[i]);
        }
        return window.btoa(binary);
    }

    static formatVehicleType(vtype) {
        const lang = (typeof I18nManager !== 'undefined') ? I18nManager.getLang() : 'km';
        switch (vtype) {
            case 'motorbike':
                return lang === 'km' ? 'ម៉ូតូ' : 'Motorbike';
            case 'small_car':
                return lang === 'km' ? 'ឡានតូច / Sedan' : 'Small Car / Sedan';
            case 'suv_pickup':
                return lang === 'km' ? 'ឡានធំ / SUV / Pickup' : 'SUV / Pickup';
            case 'van_truck':
                return lang === 'km' ? 'ឡានដឹក / វ៉ាន់' : 'Van / Truck';
            default:
                return vtype || (lang === 'km' ? 'រថយន្ត' : 'Vehicle');
        }
    }
}

// Expose globally
window.PosThermalPrinter = PosThermalPrinter;
