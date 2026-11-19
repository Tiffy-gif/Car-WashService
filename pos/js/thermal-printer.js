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
        const isSettingOn = (val, defaultVal = true) => {
            if (val === undefined || val === null || val === '') return defaultVal;
            return val === '1' || val === 1 || val === true;
        };
        const paperSize = settings.receipt_paper_size || '80mm';
        const width = (!isWorkshop && paperSize === '58mm') ? 384 : this.DOT_WIDTH; // 384 for 58mm, 576 for 80mm
        const margin = (!isWorkshop && paperSize === '58mm') ? 22 : 36; // Safe margins on left and right
        const lang = (typeof I18nManager !== 'undefined') ? I18nManager.getLang() : 'km';

        // Estimate canvas height dynamically (generous buffer to prevent clipping before crop)
        const items = order.items || [];
        let estHeight = 1000 + (items.length * 70);
        if (isReprint) estHeight += 70;
        if (order.status === 'cancelled' || order.status === 'unpaid' || order.payment_status === 'unpaid') estHeight += 60;
        if (isSettingOn(settings.receipt_show_oil_reminder, true)) estHeight += 120;
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
        // B. CUSTOMER RECEIPT (Unified Settings Sync)
        // ==========================================

        const rcptLang = settings.receipt_language || 'en';
        const spacingMode = settings.receipt_line_spacing || 'normal';
        const spacingMul = spacingMode === 'compact' ? 0.82 : (spacingMode === 'relaxed' ? 1.22 : 1.0);

        const fontSizeMode = settings.receipt_font_size || 'normal';
        const fontMul = fontSizeMode === 'small' ? 0.88 : (fontSizeMode === 'large' ? 1.15 : 1.0);

        const isBold = settings.receipt_bold_mode !== '0' && settings.receipt_bold_mode !== 0 && settings.receipt_bold_mode !== false;
        const boldWeight = isBold ? 'bold ' : '';
        const headerAlign = settings.receipt_header_align || 'center';

        const getLbl = (key, fallbackKm, fallbackEn, fallbackBoth) => {
            if (settings['receipt_label_' + key]) return settings['receipt_label_' + key];
            if (rcptLang === 'km') return fallbackKm;
            if (rcptLang === 'en') return fallbackEn;
            return fallbackBoth;
        };

        const drawHeaderLine = (text, fontStr, yPos) => {
            ctx.font = fontStr;
            if (headerAlign === 'left') {
                ctx.textAlign = 'left';
                ctx.fillText(text, margin, yPos);
            } else if (headerAlign === 'right') {
                ctx.textAlign = 'right';
                ctx.fillText(text, width - margin, yPos);
            } else {
                ctx.textAlign = 'center';
                ctx.fillText(text, width / 2, yPos);
            }
        };

        // 1. Logo
        const showLogo = isSettingOn(settings.receipt_show_logo, true);
        const rawLogo = (typeof PosStorage !== 'undefined' ? PosStorage.getLogoDataUri() : null)
            || settings.shop_logo_url
            || settings.shop_logo
            || settings.logo_url
            || settings.logo;
        let logoSrc = (typeof PosApp !== 'undefined' && PosApp.resolveAssetUrl)
            ? (PosApp.resolveAssetUrl(rawLogo) || rawLogo)
            : rawLogo;
        if (logoSrc && !logoSrc.startsWith('data:') && !logoSrc.startsWith('http') && !logoSrc.startsWith('blob:')) {
            logoSrc = '../' + logoSrc.replace(/^\/+/, '');
        }
        
        if (showLogo && logoSrc) {
            try {
                const img = await this.loadImage(logoSrc);
                if (img) {
                    const logoSize = settings.receipt_logo_size || 'medium';
                    let maxW = 220;
                    let maxH = 80;
                    if (paperSize === '58mm') {
                        if (logoSize === 'small') { maxW = 110; maxH = 40; }
                        else if (logoSize === 'large') { maxW = 200; maxH = 75; }
                        else { maxW = 150; maxH = 55; }
                    } else {
                        if (logoSize === 'small') { maxW = 150; maxH = 52; }
                        else if (logoSize === 'large') { maxW = 300; maxH = 105; }
                        else { maxW = 220; maxH = 80; }
                    }

                    let imgW = img.width;
                    let imgH = img.height;
                    const scale = Math.min(maxW / imgW, maxH / imgH, 1);
                    imgW *= scale;
                    imgH *= scale;

                    let logoX = (width - imgW) / 2;
                    if (headerAlign === 'left') logoX = margin;
                    else if (headerAlign === 'right') logoX = width - margin - imgW;

                    ctx.drawImage(img, logoX, y, imgW, imgH);
                    y += imgH + Math.round(14 * spacingMul);
                }
            } catch (e) {
                console.warn('Could not render logo to canvas:', e);
            }
        }

        // 2. Shop Names
        const showShopName = isSettingOn(settings.receipt_show_shop_name, true);
        if (showShopName) {
            if (rcptLang !== 'en') {
                ctx.font = `${boldWeight}${Math.round(26 * fontMul)}px ${this.KHMER_FONT_STACK}`;
                const nameKmLines = this.wrapText(ctx, settings.shop_name_km || 'សេវាកម្មលាងរថយន្ត និងម៉ូតូទំនើប', width - (margin * 2));
                nameKmLines.forEach(ln => {
                    drawHeaderLine(ln, ctx.font, y);
                    y += Math.round(32 * spacingMul);
                });
            }
            if (rcptLang !== 'km') {
                ctx.font = `${boldWeight}${Math.round(22 * fontMul)}px "Segoe UI", sans-serif`;
                const nameEnLines = this.wrapText(ctx, settings.shop_name_en || 'Car Wash & Detailing', width - (margin * 2));
                nameEnLines.forEach(ln => {
                    drawHeaderLine(ln, ctx.font, y);
                    y += Math.round(28 * spacingMul);
                });
            }
        }

        // 3. Address and Phone (Wrapped with safe left/right margins)
        const showAddress = isSettingOn(settings.receipt_show_address, true);
        if (showAddress && settings.shop_address) {
            ctx.font = `${isBold ? '600 ' : ''}${Math.round(17 * fontMul)}px ${this.KHMER_FONT_STACK}`;
            const addrLines = this.wrapText(ctx, settings.shop_address, width - (margin * 2));
            addrLines.forEach(line => {
                drawHeaderLine(line, ctx.font, y);
                y += Math.round(22 * spacingMul);
            });
        }

        const showPhone = isSettingOn(settings.receipt_show_phone, true);
        if (showPhone && settings.shop_phone) {
            ctx.font = `${isBold ? '600 ' : ''}${Math.round(17 * fontMul)}px ${this.KHMER_FONT_STACK}`;
            drawHeaderLine(`Tel: ${settings.shop_phone}`, ctx.font, y);
            y += Math.round(24 * spacingMul);
        }

        // Welcome / Slogan Line
        if (settings.receipt_welcome_line && settings.receipt_welcome_line.trim()) {
            ctx.font = `${boldWeight}${Math.round(16 * fontMul)}px ${this.KHMER_FONT_STACK}`;
            const welcomeLines = this.wrapText(ctx, settings.receipt_welcome_line.trim(), width - (margin * 2));
            welcomeLines.forEach(wLine => {
                drawHeaderLine(wLine, ctx.font, y);
                y += Math.round(22 * spacingMul);
            });
        }

        // Extra Top Lines (e.g. Promotion, Facebook, Wifi)
        if (settings.receipt_header_extra && settings.receipt_header_extra.trim()) {
            ctx.font = `${isBold ? '600 ' : ''}${Math.round(15 * fontMul)}px ${this.KHMER_FONT_STACK}`;
            const hLines = this.wrapText(ctx, settings.receipt_header_extra.trim(), width - (margin * 2));
            hLines.forEach(ln => {
                drawHeaderLine(ln, ctx.font, y);
                y += Math.round(22 * spacingMul);
            });
        }

        this.drawDashedLine(ctx, y, width, margin);
        y += Math.round(24 * spacingMul);

        // Banners for Reprint, Cancelled, Unpaid
        const isCancelled = order.status === 'cancelled';
        const isUnpaid = (order.payment_status === 'unpaid' || order.status === 'unpaid') && !isCancelled;

        if (isReprint) {
            ctx.strokeRect(margin, y, width - (margin * 2), Math.round(48 * spacingMul));
            ctx.textAlign = 'center';
            ctx.font = `${boldWeight}${Math.round(19 * fontMul)}px "Segoe UI", ${this.KHMER_FONT_STACK}`;
            const reprintTitle = rcptLang === 'km' ? '*** ច្បាប់ចម្លង ***' : (rcptLang === 'en' ? '*** COPY / REPRINT ***' : '*** ច្បាប់ចម្លង / COPY / REPRINT ***');
            ctx.fillText(reprintTitle, width / 2, y + Math.round(24 * spacingMul));
            ctx.font = `${Math.round(13 * fontMul)}px "Segoe UI", sans-serif`;
            ctx.fillText(`Reprinted: ${new Date().toLocaleString()}`, width / 2, y + Math.round(42 * spacingMul));
            y += Math.round(58 * spacingMul);
        }

        if (isCancelled) {
            ctx.fillRect(margin, y, width - (margin * 2), Math.round(40 * spacingMul));
            ctx.fillStyle = '#FFFFFF';
            ctx.textAlign = 'center';
            ctx.font = `${boldWeight}${Math.round(20 * fontMul)}px "Segoe UI", ${this.KHMER_FONT_STACK}`;
            ctx.fillText(rcptLang === 'km' ? '*** បានបោះបង់ ***' : (rcptLang === 'en' ? '*** CANCELLED ***' : '*** បានបោះបង់ / ORDER CANCELLED ***'), width / 2, y + Math.round(28 * spacingMul));
            ctx.fillStyle = '#000000';
            y += Math.round(50 * spacingMul);
        } else if (isUnpaid) {
            ctx.strokeRect(margin, y, width - (margin * 2), Math.round(40 * spacingMul));
            ctx.textAlign = 'center';
            ctx.font = `${boldWeight}${Math.round(20 * fontMul)}px "Segoe UI", ${this.KHMER_FONT_STACK}`;
            ctx.fillText(rcptLang === 'km' ? '*** មិនទាន់ទូទាត់ ***' : (rcptLang === 'en' ? '*** UNPAID ***' : '*** មិនទាន់ទូទាត់ / UNPAID ***'), width / 2, y + Math.round(28 * spacingMul));
            y += Math.round(50 * spacingMul);
        }

        // 4. Order Metadata (Invoice No, Date, Cashier, Customer, Washer)
        const showInvoiceNo = isSettingOn(settings.receipt_show_invoice_no, true);
        const showDate = isSettingOn(settings.receipt_show_date, true);
        const showCashier = isSettingOn(settings.receipt_show_cashier, true);
        const showCustomer = isSettingOn(settings.receipt_show_customer, true);
        const showWasher = isSettingOn(settings.receipt_show_washer, false);

        ctx.textAlign = 'left';
        ctx.font = `${isBold ? '600 ' : ''}${Math.round(18 * fontMul)}px "Segoe UI", ${this.KHMER_FONT_STACK}`;

        if (showInvoiceNo || showDate) {
            const invLbl = getLbl('invoice', 'វិក្កយបត្រ៖', 'Invoice #:', 'វិក្កយបត្រ / Invoice #:');
            const invVal = order.order_number || 'ORD-000';
            const leftTxt = showInvoiceNo ? `${invLbl} ${invVal}` : '';
            const rightTxt = showDate ? (order.created_at || new Date().toLocaleString()) : '';
            this.drawTwoColumns(ctx, leftTxt, rightTxt, margin, width - margin, y);
            y += Math.round(24 * spacingMul);
        }

        if (showCashier || showCustomer) {
            const cashLbl = getLbl('cashier', 'បេឡា៖', 'Cashier:', 'បេឡា / Cashier:');
            const cashVal = order.cashier_name || 'Shop Owner';
            const custLbl = getLbl('customer', 'អតិថិជន៖', 'Customer:', 'អតិថិជន / Customer:');
            const custVal = order.customer_name || 'Walk-in Guest';
            const leftTxt = showCashier ? `${cashLbl} ${cashVal}` : '';
            const rightTxt = showCustomer ? `${custLbl} ${custVal}` : '';
            this.drawTwoColumns(ctx, leftTxt, rightTxt, margin, width - margin, y);
            y += Math.round(26 * spacingMul);
        }

        if (showWasher && (order.washers || order.washer_name)) {
            const catalog = (typeof PosStorage !== 'undefined' ? PosStorage.getCatalog() : {}) || {};
            const washers = catalog.washers || [];
            let wName = order.washer_name || '';
            if (!wName && Array.isArray(order.washers)) {
                wName = order.washers.map(id => washers.find(w => w.id == id)?.full_name || `#${id}`).join(', ');
            }
            if (wName) {
                this.drawTwoColumns(ctx, `${getLbl('washer', 'ជាងលាង៖', 'Washer:', 'ជាងលាង / Washer:')} ${wName}`, '', margin, width - margin, y);
                y += Math.round(24 * spacingMul);
            }
        }

        // Vehicle Plate Tag
        const showPlate = isSettingOn(settings.receipt_show_plate, true);
        const showVehicleType = isSettingOn(settings.receipt_show_vehicle_type, false);

        if (showPlate) {
            const barHeight = Math.round(44 * spacingMul);
            ctx.fillStyle = '#000000';
            ctx.fillRect(margin, y, width - (margin * 2), barHeight);
            ctx.fillStyle = '#FFFFFF';
            ctx.font = `bold ${Math.round(22 * fontMul)}px "Segoe UI", sans-serif`;
            ctx.textAlign = 'left';
            const vtype = (showVehicleType && order.vehicle_type) ? ` (${order.vehicle_type.replace(/_/g, ' ').toUpperCase()})` : '';
            ctx.fillText(`${getLbl('plate', 'ស្លាកលេខ៖', 'PLATE #:', 'PLATE #:')}${vtype}`, margin + 14, y + Math.round(30 * spacingMul));
            ctx.textAlign = 'right';
            ctx.fillText(order.plate_number || 'WALK-IN', width - margin - 14, y + Math.round(30 * spacingMul));
            ctx.fillStyle = '#000000';
            y += barHeight + Math.round(16 * spacingMul);
        }

        this.drawDashedLine(ctx, y, width, margin);
        y += Math.round(24 * spacingMul);

        // 5. Items Table Header
        const colQtyOffset = Math.round(120 * (width / 576));
        ctx.font = `bold ${Math.round(18 * fontMul)}px "Segoe UI", ${this.KHMER_FONT_STACK}`;
        ctx.textAlign = 'left';
        ctx.fillText(getLbl('item', 'ទំនិញ/សេវា', 'ITEM', 'ITEM / ទំនិញ'), margin, y);
        ctx.textAlign = 'center';
        ctx.fillText(getLbl('qty', 'ចំនួន', 'QTY', 'QTY'), width - colQtyOffset, y);
        ctx.textAlign = 'right';
        ctx.fillText(getLbl('price', 'តម្លៃ', 'PRICE', 'PRICE'), width - margin, y);
        y += Math.round(14 * spacingMul);

        this.drawDashedLine(ctx, y, width, margin);
        y += Math.round(24 * spacingMul);

        // Items Rows
        ctx.font = `${isBold ? '600 ' : ''}${Math.round(19 * fontMul)}px ${this.KHMER_FONT_STACK}`;
        items.forEach(it => {
            const name = (rcptLang === 'km' && it.name_km) ? it.name_km : ((rcptLang === 'en' && it.name_en) ? it.name_en : (it.name || it.name_en || it.service_name || 'Item'));
            const lineTotal = `$${((it.price_usd || 0) * (it.qty || 1)).toFixed(2)}`;
            ctx.textAlign = 'left';
            ctx.fillText(name, margin, y);
            ctx.textAlign = 'center';
            ctx.fillText(String(it.qty || 1), width - colQtyOffset, y);
            ctx.textAlign = 'right';
            ctx.fillText(lineTotal, width - margin, y);
            y += Math.round(28 * spacingMul);
        });

        // Item count summary
        const showItemCount = isSettingOn(settings.receipt_show_item_count, false);
        if (showItemCount) {
            const totUnits = items.reduce((s, it) => s + (it.qty || 1), 0);
            ctx.font = `${isBold ? '600 ' : ''}${Math.round(15 * fontMul)}px "Segoe UI", sans-serif`;
            ctx.textAlign = 'left';
            ctx.fillText(`Total Items: ${items.length} (${totUnits} Units)`, margin, y);
            y += Math.round(22 * spacingMul);
        }

        this.drawDashedLine(ctx, y, width, margin);
        y += Math.round(24 * spacingMul);

        // 6. Totals
        const subtotal = items.reduce((sum, it) => sum + ((it.price_usd || 0) * (it.qty || 1)), 0);
        ctx.font = `${isBold ? '600 ' : ''}${Math.round(18 * fontMul)}px "Segoe UI", ${this.KHMER_FONT_STACK}`;
        this.drawTwoColumns(ctx, getLbl('subtotal', 'សរុបបឋម៖', 'Subtotal:', 'សរុបបឋម / Subtotal:'), `$${subtotal.toFixed(2)}`, margin, width - margin, y);
        y += Math.round(26 * spacingMul);

        const showDiscount = isSettingOn(settings.receipt_show_discount, true);
        if (showDiscount && order.discount_usd > 0) {
            this.drawTwoColumns(ctx, getLbl('discount', 'បញ្ចុះតម្លៃ៖', 'Discount:', 'បញ្ចុះតម្លៃ / Discount:'), `-$${parseFloat(order.discount_usd).toFixed(2)}`, margin, width - margin, y);
            y += Math.round(26 * spacingMul);
        }

        const showTax = isSettingOn(settings.receipt_show_tax, false);
        if (showTax) {
            const taxRate = 0.10;
            const taxAmount = (subtotal - (order.discount_usd || 0)) * taxRate;
            this.drawTwoColumns(ctx, getLbl('tax', 'ពន្ធ / Tax (10%):', 'Tax / VAT (10%):', 'ពន្ធ / Tax (10%):'), `$${taxAmount.toFixed(2)}`, margin, width - margin, y);
            y += Math.round(26 * spacingMul);
        }

        this.drawDashedLine(ctx, y, width, margin);
        y += Math.round(28 * spacingMul);

        // Big Total Lines
        ctx.font = `bold ${Math.round(28 * fontMul)}px "Segoe UI", sans-serif`;
        this.drawTwoColumns(ctx, getLbl('total_usd', 'សរុប USD៖', 'TOTAL USD:', 'សរុប USD / TOTAL USD:'), `$${parseFloat(order.total_usd || subtotal).toFixed(2)}`, margin, width - margin, y);
        y += Math.round(34 * spacingMul);

        ctx.font = `bold ${Math.round(22 * fontMul)}px ${this.KHMER_FONT_STACK}`;
        this.drawTwoColumns(ctx, getLbl('total_khr', 'សរុប រៀល៖', 'TOTAL KHR:', 'សរុប រៀល / TOTAL KHR:'), `${parseInt(order.total_khr || 0).toLocaleString()} ៛`, margin, width - margin, y);
        y += Math.round(26 * spacingMul);

        const showExchangeRate = isSettingOn(settings.receipt_show_exchange_rate, false) || order.exchange_rate || order.show_exchange_rate;
        if (showExchangeRate) {
            const exchangeRateVal = order.exchange_rate || (typeof PosApp !== 'undefined' ? PosApp.getExchangeRate() : 4100) || 4100;
            ctx.textAlign = 'right';
            ctx.font = `${isBold ? '600 ' : ''}${Math.round(15 * fontMul)}px "Segoe UI", ${this.KHMER_FONT_STACK}`;
            ctx.fillText(`${getLbl('rate', 'អត្រាប្តូរប្រាក់៖', 'Rate:', 'អត្រាប្តូរប្រាក់ / Rate:')} $1.00 = ${exchangeRateVal.toLocaleString()} ៛`, width - margin, y);
            y += Math.round(22 * spacingMul);
        }

        this.drawSolidLine(ctx, y, width, margin);
        y += Math.round(26 * spacingMul);

        // 7. Payment Info
        const showPayment = isSettingOn(settings.receipt_show_payment, true);
        if (showPayment) {
            ctx.font = `bold ${Math.round(19 * fontMul)}px "Segoe UI", ${this.KHMER_FONT_STACK}`;
            let methodStr = (order.payment_method || 'CASH').toUpperCase();
            if (isUnpaid) {
                methodStr = rcptLang === 'km' ? 'មិនទាន់ទូទាត់' : 'UNPAID';
            } else if (methodStr === 'CASH') {
                methodStr = rcptLang === 'en' ? 'CASH' : (rcptLang === 'km' ? 'សាច់ប្រាក់' : 'សាច់ប្រាក់ (CASH)');
            }
            this.drawTwoColumns(ctx, getLbl('payment', 'វិធីទូទាត់៖', 'Payment:', 'វិធីទូទាត់ / Payment:'), methodStr, margin, width - margin, y);
            y += Math.round(26 * spacingMul);

            if (!isUnpaid && (order.payment_method || 'CASH').toUpperCase() === 'CASH') {
                ctx.font = `${isBold ? '600 ' : ''}${Math.round(18 * fontMul)}px "Segoe UI", sans-serif`;
                const tendered = parseFloat(order.amount_tendered_usd || order.total_usd || 0);
                const change = parseFloat(order.change_usd || 0);
                this.drawTwoColumns(ctx, getLbl('tendered', 'ប្រាក់ទទួល៖', 'Tendered:', 'ប្រាក់ទទួល / Tendered:'), `$${tendered.toFixed(2)}`, margin, width - margin, y);
                y += Math.round(24 * spacingMul);
                this.drawTwoColumns(ctx, getLbl('change', 'ប្រាក់អាប់៖', 'Change:', 'ប្រាក់អាប់ / Change:'), `$${change.toFixed(2)}`, margin, width - margin, y);
                y += Math.round(28 * spacingMul);
            } else if (isUnpaid) {
                ctx.font = `bold ${Math.round(19 * fontMul)}px "Segoe UI", sans-serif`;
                this.drawTwoColumns(ctx, rcptLang === 'km' ? 'ប្រាក់ត្រូវបង់៖' : 'Amount Due:', `$${parseFloat(order.total_usd || 0).toFixed(2)}`, margin, width - margin, y);
                y += Math.round(28 * spacingMul);
            }
        }

        // 8. Oil Care Reminder Box
        const showOilReminder = isSettingOn(settings.receipt_show_oil_reminder, true);
        if (showOilReminder) {
            y += Math.round(8 * spacingMul);
            const boxH = Math.round(66 * spacingMul);
            ctx.strokeRect(margin, y, width - (margin * 2), boxH);
            ctx.textAlign = 'center';
            ctx.font = `bold ${Math.round(17 * fontMul)}px ${this.KHMER_FONT_STACK}`;
            const oilTitle = rcptLang === 'km' ? 'រំលឹកប្តូរប្រេង' : (rcptLang === 'en' ? 'OIL CARE REMINDER' : 'OIL CARE REMINDER / រំលឹកប្តូរប្រេង');
            ctx.fillText(oilTitle, width / 2, y + Math.round(24 * spacingMul));
            ctx.font = `${isBold ? '600 ' : ''}${Math.round(15 * fontMul)}px "Segoe UI", sans-serif`;

            let mileageText = '';
            if (order.current_mileage && parseFloat(order.current_mileage) > 0) {
                const curKm = parseFloat(order.current_mileage);
                const nextKm = order.next_oil_mileage || (curKm + (parseInt(settings.default_oil_mileage) || 5000));
                const nextDate = order.next_oil_date ? ` (Due: ${order.next_oil_date})` : '';
                mileageText = `Current: ${curKm.toLocaleString()} km | Next: ${nextKm.toLocaleString()} km${nextDate}`;
            } else {
                mileageText = 'Current: ________ km | Next: ________ km';
            }
            ctx.fillText(mileageText, width / 2, y + Math.round(48 * spacingMul));
            y += boxH + Math.round(16 * spacingMul);
        }

        // Notes Box
        const showNotes = isSettingOn(settings.receipt_show_notes, true);
        if (showNotes && order.repair_notes) {
            y += Math.round(8 * spacingMul);
            const boxH = Math.round(62 * spacingMul);
            ctx.strokeRect(margin, y, width - (margin * 2), boxH);
            ctx.textAlign = 'center';
            ctx.font = `bold ${Math.round(16 * fontMul)}px ${this.KHMER_FONT_STACK}`;
            const noteTitle = rcptLang === 'km' ? 'ចំណាំត្រួតពិនិត្យ' : (rcptLang === 'en' ? 'INSPECTION NOTE' : 'ចំណាំត្រួតពិនិត្យ / INSPECTION NOTE');
            ctx.fillText(noteTitle, width / 2, y + Math.round(22 * spacingMul));
            ctx.font = `${isBold ? '600 ' : ''}${Math.round(15 * fontMul)}px ${this.KHMER_FONT_STACK}`;
            ctx.fillText(order.repair_notes, width / 2, y + Math.round(44 * spacingMul));
            y += boxH + Math.round(16 * spacingMul);
        }

        // Extra Footer Lines (Promotion / Policy)
        if (settings.receipt_footer_extra && settings.receipt_footer_extra.trim()) {
            y += Math.round(6 * spacingMul);
            ctx.font = `${isBold ? '600 ' : ''}${Math.round(15 * fontMul)}px ${this.KHMER_FONT_STACK}`;
            const fLines = this.wrapText(ctx, settings.receipt_footer_extra.trim(), width - (margin * 2));
            fLines.forEach(ln => {
                drawHeaderLine(ln, ctx.font, y);
                y += Math.round(22 * spacingMul);
            });
        }

        // 9. Thank You Footer
        const showFooter = isSettingOn(settings.receipt_show_footer, true);
        if (showFooter) {
            y += Math.round(8 * spacingMul);
            this.drawDashedLine(ctx, y, width, margin);
            y += Math.round(24 * spacingMul);

            ctx.textAlign = 'center';
            ctx.font = `bold ${Math.round(19 * fontMul)}px ${this.KHMER_FONT_STACK}`;
            const footerMsg = settings.receipt_footer || 'Thank you for your visit!';
            const footerLines = this.wrapText(ctx, footerMsg, width - (margin * 2));
            footerLines.forEach(line => {
                ctx.fillText(line.trim(), width / 2, y);
                y += Math.round(24 * spacingMul);
            });

            const bottomLine = settings.receipt_bottom_line || 'Powered by Counter POS';
            ctx.font = `${isBold ? '600 ' : ''}${Math.round(14 * fontMul)}px "Segoe UI", sans-serif`;
            ctx.fillText(bottomLine, width / 2, y);
            y += Math.round(30 * spacingMul);
        }

        // Extra 70px generous whitespace buffer before physical cut to prevent cutting text
        y += 70;

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

                        // High-contrast binarization: luminance < 215 with alpha > 60
                        // Captures crisp font stroke edges so text prints deep, solid black with no thin/faint lines
                        const luminance = (0.299 * r) + (0.587 * g) + (0.114 * bVal);
                        if (a > 60 && luminance < 215) {
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

        // 4. Feed paper well past printhead and cutter blade (ESC d 6 feeds 6 blank lines)
        buffer.push(0x1B, 0x64, 0x06);

        // 5. Open Cash Drawer if applicable (ESC p 0)
        if (options.drawer) {
            buffer.push(...this.CMD.DRAWER_KICK_PIN2);
            buffer.push(...this.CMD.DRAWER_KICK_PIN5);
        }

        // 6. Paper Cut Command (GS V 66 3 feeds 3 lines and cuts)
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
    static wrapText(ctx, text, maxWidth) {
        if (!text) return [];
        const paragraphs = String(text).split('\n');
        const lines = [];

        paragraphs.forEach(paragraph => {
            const words = paragraph.split(' ');
            let currentLine = '';

            words.forEach(word => {
                const testLine = currentLine ? `${currentLine} ${word}` : word;
                const metrics = ctx.measureText(testLine);
                if (metrics.width > maxWidth && currentLine) {
                    lines.push(currentLine);
                    currentLine = word;
                } else {
                    currentLine = testLine;
                }
            });
            if (currentLine) {
                lines.push(currentLine);
            }
        });

        return lines;
    }

    static drawDashedLine(ctx, y, width, margin = 30) {
        ctx.save();
        ctx.setLineDash([6, 5]);
        ctx.lineWidth = 1.5;
        ctx.beginPath();
        ctx.moveTo(margin, y);
        ctx.lineTo(width - margin, y);
        ctx.stroke();
        ctx.restore();
    }

    static drawSolidLine(ctx, y, width, margin = 30) {
        ctx.save();
        ctx.lineWidth = 2;
        ctx.beginPath();
        ctx.moveTo(margin, y);
        ctx.lineTo(width - margin, y);
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

    /**
     * Render Open Float Slip onto 80mm Canvas
     */
    static async renderOpenShiftSlipToCanvas(shiftData) {
        if (typeof document !== 'undefined' && document.fonts && document.fonts.ready) {
            try { await document.fonts.ready; } catch (e) {}
        }

        const width = this.DOT_WIDTH; // 576 dots
        const estHeight = 720;
        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = estHeight;
        const ctx = canvas.getContext('2d');

        ctx.fillStyle = '#FFFFFF';
        ctx.fillRect(0, 0, width, estHeight);
        ctx.fillStyle = '#000000';

        const margin = 30;
        let y = 30;

        const shop = shiftData.shop || {};
        const shopKm = shop.shop_name_km || 'សេវាកម្មលាងរថយន្ត និងម៉ូតូ';
        const shopEn = shop.shop_name_en || 'My Car Wash';
        const shopPhone = shop.shop_phone || '012 334 556';
        const shopAddr = shop.shop_address || 'Phnom Penh, Cambodia';

        // Shop header
        ctx.textAlign = 'center';
        ctx.font = `bold 24px ${this.KHMER_FONT_STACK}`;
        ctx.fillText(shopKm, width / 2, y);
        y += 30;
        ctx.font = 'bold 20px "Segoe UI", sans-serif';
        ctx.fillText(shopEn, width / 2, y);
        y += 24;
        ctx.font = `15px ${this.KHMER_FONT_STACK}`;
        ctx.fillText(shopAddr, width / 2, y);
        y += 20;
        ctx.fillText(`Tel: ${shopPhone}`, width / 2, y);
        y += 26;

        this.drawSolidLine(ctx, y, width, margin);
        y += 26;

        // Title
        ctx.font = 'bold 22px "Segoe UI", sans-serif';
        ctx.fillText('OPEN FLOAT SLIP', width / 2, y);
        y += 24;
        ctx.font = `bold 18px ${this.KHMER_FONT_STACK}`;
        ctx.fillText('សន្លឹកបើកវេន (FLOAT)', width / 2, y);
        y += 24;

        this.drawDashedLine(ctx, y, width, margin);
        y += 26;

        // Metadata
        ctx.font = '16px "Segoe UI", sans-serif';
        this.drawTwoColumns(ctx, 'Shift #:', shiftData.shift_uid || '', margin, width - margin, y);
        y += 24;
        this.drawTwoColumns(ctx, 'Cashier:', `${shiftData.cashier_name || ''} (${shiftData.cashier_role || 'Cashier'})`, margin, width - margin, y);
        y += 24;
        this.drawTwoColumns(ctx, 'Terminal:', shiftData.device_name || 'Counter POS', margin, width - margin, y);
        y += 24;
        const openTime = shiftData.started_at ? new Date(shiftData.started_at.replace(/-/g, '/')).toLocaleString() : new Date().toLocaleString();
        this.drawTwoColumns(ctx, 'Opened At:', openTime, margin, width - margin, y);
        y += 24;
        this.drawTwoColumns(ctx, 'Exchange Rate:', `$1.00 = ${(shiftData.exchange_rate || 4100).toLocaleString()} ៛`, margin, width - margin, y);
        y += 26;

        this.drawDashedLine(ctx, y, width, margin);
        y += 26;

        // Amounts
        ctx.font = 'bold 18px "Segoe UI", sans-serif';
        this.drawTwoColumns(ctx, 'Opening Float USD:', `$${parseFloat(shiftData.opening_cash_usd || 0).toFixed(2)}`, margin, width - margin, y);
        y += 26;
        this.drawTwoColumns(ctx, 'Opening Float KHR:', `${parseInt(shiftData.opening_cash_khr || 0).toLocaleString()} ៛`, margin, width - margin, y);
        y += 28;

        this.drawSolidLine(ctx, y, width, margin);
        y += 28;

        ctx.font = 'bold 20px "Segoe UI", sans-serif';
        const totalOpUsd = shiftData.total_opening_usd !== undefined ? parseFloat(shiftData.total_opening_usd) : (parseFloat(shiftData.opening_cash_usd || 0) + (parseInt(shiftData.opening_cash_khr || 0) / (shiftData.exchange_rate || 4100)));
        this.drawTwoColumns(ctx, 'Total Float (USD):', `$${totalOpUsd.toFixed(2)}`, margin, width - margin, y);
        y += 35;

        // Signatures
        y += 20;
        this.drawDashedLine(ctx, y, width, margin);
        y += 45;

        ctx.font = 'bold 15px "Segoe UI", sans-serif';
        ctx.textAlign = 'left';
        ctx.fillText('Cashier: _________________', margin, y);
        ctx.textAlign = 'right';
        ctx.fillText('Manager: _________________', width - margin, y);
        y += 35;

        ctx.textAlign = 'center';
        ctx.font = '13px "Segoe UI", sans-serif';
        ctx.fillText(`Printed on ${new Date().toLocaleString()} | Counter POS`, width / 2, y);
        y += 70; // safe cut buffer

        return this.cropCanvasHeight(canvas, y);
    }

    /**
     * Render End Shift Slip onto 80mm Canvas
     */
    static async renderEndShiftSlipToCanvas(shiftData) {
        if (typeof document !== 'undefined' && document.fonts && document.fonts.ready) {
            try { await document.fonts.ready; } catch (e) {}
        }

        const width = this.DOT_WIDTH;
        const estHeight = 1200;
        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = estHeight;
        const ctx = canvas.getContext('2d');

        ctx.fillStyle = '#FFFFFF';
        ctx.fillRect(0, 0, width, estHeight);
        ctx.fillStyle = '#000000';

        const margin = 30;
        let y = 30;

        const shop = shiftData.shop || {};
        const shopKm = shop.shop_name_km || 'សេវាកម្មលាងរថយន្ត និងម៉ូតូ';
        const shopEn = shop.shop_name_en || 'My Car Wash';
        const shopPhone = shop.shop_phone || '012 334 556';
        const shopAddr = shop.shop_address || 'Phnom Penh, Cambodia';

        // Header
        ctx.textAlign = 'center';
        ctx.font = `bold 24px ${this.KHMER_FONT_STACK}`;
        ctx.fillText(shopKm, width / 2, y);
        y += 30;
        ctx.font = 'bold 20px "Segoe UI", sans-serif';
        ctx.fillText(shopEn, width / 2, y);
        y += 24;
        ctx.font = `15px ${this.KHMER_FONT_STACK}`;
        ctx.fillText(shopAddr, width / 2, y);
        y += 20;
        ctx.fillText(`Tel: ${shopPhone}`, width / 2, y);
        y += 26;

        this.drawSolidLine(ctx, y, width, margin);
        y += 26;

        // Title
        const isHandover = !shiftData.is_solo && !!shiftData.handover_to_name;
        ctx.font = 'bold 22px "Segoe UI", sans-serif';
        ctx.fillText(isHandover ? 'SHIFT HANDOVER SLIP' : 'SOLO END SHIFT SLIP', width / 2, y);
        y += 24;
        ctx.font = `bold 18px ${this.KHMER_FONT_STACK}`;
        ctx.fillText(isHandover ? 'សន្លឹកផ្ទេរវេនបេឡា' : 'សន្លឹកបិទវេនបេឡាតែឯង', width / 2, y);
        y += 24;

        this.drawDashedLine(ctx, y, width, margin);
        y += 26;

        ctx.font = '16px "Segoe UI", sans-serif';
        this.drawTwoColumns(ctx, 'Shift #:', shiftData.shift_uid || '', margin, width - margin, y);
        y += 24;
        this.drawTwoColumns(ctx, 'Cashier:', `${shiftData.cashier_name || ''} (${shiftData.cashier_role || 'Cashier'})`, margin, width - margin, y);
        y += 24;
        if (isHandover) {
            this.drawTwoColumns(ctx, 'Handover To:', `${shiftData.handover_to_name} (${shiftData.handover_to_role || 'Cashier'})`, margin, width - margin, y);
            y += 24;
        }
        this.drawTwoColumns(ctx, 'Terminal:', shiftData.device_name || 'Counter POS', margin, width - margin, y);
        y += 24;
        const stTime = shiftData.started_at ? new Date(shiftData.started_at.replace(/-/g, '/')).toLocaleString() : '';
        const edTime = shiftData.ended_at ? new Date(shiftData.ended_at.replace(/-/g, '/')).toLocaleString() : new Date().toLocaleString();
        this.drawTwoColumns(ctx, 'Started:', stTime, margin, width - margin, y);
        y += 24;
        this.drawTwoColumns(ctx, 'Ended:', edTime, margin, width - margin, y);
        y += 24;
        this.drawTwoColumns(ctx, 'Exchange Rate:', `$1.00 = ${(shiftData.exchange_rate || 4100).toLocaleString()} ៛`, margin, width - margin, y);
        y += 26;

        this.drawDashedLine(ctx, y, width, margin);
        y += 24;

        // Sales summary
        ctx.font = 'bold 16px "Segoe UI", sans-serif';
        ctx.textAlign = 'left';
        ctx.fillText('SHIFT SALES SUMMARY', margin, y);
        y += 24;

        ctx.font = '16px "Segoe UI", sans-serif';
        this.drawTwoColumns(ctx, `Cash Sales (${shiftData.cash_sales_count || 0}):`, `$${parseFloat(shiftData.cash_sales_usd || 0).toFixed(2)}`, margin, width - margin, y);
        y += 24;
        this.drawTwoColumns(ctx, `ABA / KHQR (${shiftData.bank_sales_count || 0}):`, `$${parseFloat(shiftData.bank_sales_usd || 0).toFixed(2)}`, margin, width - margin, y);
        y += 26;

        this.drawSolidLine(ctx, y, width, margin);
        y += 26;

        ctx.font = 'bold 18px "Segoe UI", sans-serif';
        this.drawTwoColumns(ctx, `Total Sales (${shiftData.total_orders_count || 0}):`, `$${parseFloat(shiftData.total_sales_usd || 0).toFixed(2)}`, margin, width - margin, y);
        y += 22;
        ctx.font = '12px "Segoe UI", sans-serif';
        ctx.textAlign = 'right';
        ctx.fillText('* ABA and KHQR are not in drawer cash', width - margin, y);
        y += 26;

        this.drawDashedLine(ctx, y, width, margin);
        y += 24;

        // Drawer Count Table
        ctx.font = 'bold 16px "Segoe UI", sans-serif';
        ctx.textAlign = 'left';
        ctx.fillText('DRAWER COUNT TABLE', margin, y);
        y += 24;

        ctx.font = '15px "Segoe UI", sans-serif';
        this.drawTwoColumns(ctx, 'Opening Float:', `$${parseFloat(shiftData.opening_cash_usd || 0).toFixed(2)} / ${parseInt(shiftData.opening_cash_khr || 0).toLocaleString()}៛`, margin, width - margin, y);
        y += 22;
        this.drawTwoColumns(ctx, 'Cash Received:', `+$${parseFloat(shiftData.cash_received_usd || 0).toFixed(2)} / +${parseInt(shiftData.cash_received_khr || 0).toLocaleString()}៛`, margin, width - margin, y);
        y += 22;
        this.drawTwoColumns(ctx, 'Change Given:', `-$${parseFloat(shiftData.change_given_usd || 0).toFixed(2)} / -${parseInt(shiftData.change_given_khr || 0).toLocaleString()}៛`, margin, width - margin, y);
        y += 22;
        this.drawTwoColumns(ctx, 'Cash In:', `+$${parseFloat(shiftData.cash_in_usd || 0).toFixed(2)} / +${parseInt(shiftData.cash_in_khr || 0).toLocaleString()}៛`, margin, width - margin, y);
        y += 22;
        this.drawTwoColumns(ctx, 'Cash Out:', `-$${parseFloat(shiftData.cash_out_usd || 0).toFixed(2)} / -${parseInt(shiftData.cash_out_khr || 0).toLocaleString()}៛`, margin, width - margin, y);
        y += 22;
        this.drawTwoColumns(ctx, 'Cash Refunds:', `-$${parseFloat(shiftData.cash_refunds_usd || 0).toFixed(2)} / -${parseInt(shiftData.cash_refunds_khr || 0).toLocaleString()}៛`, margin, width - margin, y);
        y += 24;

        this.drawSolidLine(ctx, y, width, margin);
        y += 24;

        ctx.font = 'bold 16px "Segoe UI", sans-serif';
        this.drawTwoColumns(ctx, 'Expected Cash:', `$${parseFloat(shiftData.expected_cash_usd || 0).toFixed(2)} / ${parseInt(shiftData.expected_cash_khr || 0).toLocaleString()}៛`, margin, width - margin, y);
        y += 24;
        this.drawTwoColumns(ctx, 'Counted Cash:', `$${parseFloat(shiftData.counted_cash_usd || 0).toFixed(2)} / ${parseInt(shiftData.counted_cash_khr || 0).toLocaleString()}៛`, margin, width - margin, y);
        y += 26;

        this.drawDashedLine(ctx, y, width, margin);
        y += 26;

        // Differences
        const diffUsd = parseFloat(shiftData.difference_usd || 0);
        const diffKhr = parseInt(shiftData.difference_khr || 0);
        const totDiffUsd = parseFloat(shiftData.total_difference_usd || 0);

        ctx.font = 'bold 16px "Segoe UI", sans-serif';
        this.drawTwoColumns(ctx, 'Difference (USD):', `${diffUsd > 0 ? '+' : ''}$${diffUsd.toFixed(2)} [${(shiftData.diff_usd_status || 'EXACT').toUpperCase()}]`, margin, width - margin, y);
        y += 24;
        this.drawTwoColumns(ctx, 'Difference (KHR):', `${diffKhr > 0 ? '+' : ''}${diffKhr.toLocaleString()}៛ [${(shiftData.diff_khr_status || 'EXACT').toUpperCase()}]`, margin, width - margin, y);
        y += 28;

        this.drawSolidLine(ctx, y, width, margin);
        y += 28;

        ctx.font = 'bold 19px "Segoe UI", sans-serif';
        this.drawTwoColumns(ctx, 'Total Diff (USD):', `${totDiffUsd > 0 ? '+' : ''}$${totDiffUsd.toFixed(2)} [${(shiftData.total_diff_status || 'EXACT').toUpperCase()}]`, margin, width - margin, y);
        y += 35;

        // Carried over unpaid / unfinished orders
        if (shiftData.unpaid_orders && shiftData.unpaid_orders.length > 0) {
            this.drawDashedLine(ctx, y, width, margin);
            y += 24;
            ctx.font = 'bold 16px "Segoe UI", sans-serif';
            ctx.textAlign = 'left';
            ctx.fillText(`CARRIED OVER ORDERS (${shiftData.unpaid_orders.length})`, margin, y);
            y += 22;
            ctx.font = '13px "Segoe UI", sans-serif';
            shiftData.unpaid_orders.forEach(uo => {
                this.drawTwoColumns(ctx, `${uo.order_number} (${uo.vehicle_plate || 'No Plate'}):`, `$${parseFloat(uo.total_usd || 0).toFixed(2)} [${(uo.status || 'UNPAID').toUpperCase()}]`, margin, width - margin, y);
                y += 20;
            });
            y += 10;
        }

        // Signatures
        this.drawDashedLine(ctx, y, width, margin);
        y += 45;

        ctx.font = 'bold 14px "Segoe UI", sans-serif';
        ctx.textAlign = 'left';
        ctx.fillText(`Outgoing: ${shiftData.cashier_name || 'Cashier'} (${shiftData.cashier_role || 'Cashier'})`, margin, y);
        ctx.textAlign = 'right';
        ctx.fillText(isHandover ? `Incoming: ${shiftData.handover_to_name} (${shiftData.handover_to_role || 'Cashier'})` : 'Manager Sign-Off', width - margin, y);
        y += 35;

        ctx.textAlign = 'center';
        ctx.font = '13px "Segoe UI", sans-serif';
        ctx.fillText(`Printed on ${new Date().toLocaleString()} | Counter POS`, width / 2, y);
        y += 70;

        return this.cropCanvasHeight(canvas, y);
    }

    /**
     * Render Close Day Slip (Z-Report) onto 80mm Canvas
     */
    static async renderCloseDaySlipToCanvas(closeData) {
        if (typeof document !== 'undefined' && document.fonts && document.fonts.ready) {
            try { await document.fonts.ready; } catch (e) {}
        }

        const width = this.DOT_WIDTH;
        const estHeight = 1450;
        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = estHeight;
        const ctx = canvas.getContext('2d');

        ctx.fillStyle = '#FFFFFF';
        ctx.fillRect(0, 0, width, estHeight);
        ctx.fillStyle = '#000000';

        const margin = 30;
        let y = 30;

        const shop = closeData.shop || {};
        const shopKm = shop.shop_name_km || 'សេវាកម្មលាងរថយន្ត និងម៉ូតូ';
        const shopEn = shop.shop_name_en || 'My Car Wash';
        const shopPhone = shop.shop_phone || '012 334 556';
        const shopAddr = shop.shop_address || 'Phnom Penh, Cambodia';

        // Header
        ctx.textAlign = 'center';
        ctx.font = `bold 24px ${this.KHMER_FONT_STACK}`;
        ctx.fillText(shopKm, width / 2, y);
        y += 30;
        ctx.font = 'bold 20px "Segoe UI", sans-serif';
        ctx.fillText(shopEn, width / 2, y);
        y += 24;
        ctx.font = `15px ${this.KHMER_FONT_STACK}`;
        ctx.fillText(shopAddr, width / 2, y);
        y += 20;
        ctx.fillText(`Tel: ${shopPhone}`, width / 2, y);
        y += 26;

        this.drawSolidLine(ctx, y, width, margin);
        y += 26;

        // Title
        ctx.font = 'bold 22px "Segoe UI", sans-serif';
        ctx.fillText('DAILY CLOSE (Z-REPORT)', width / 2, y);
        y += 24;
        ctx.font = `bold 18px ${this.KHMER_FONT_STACK}`;
        ctx.fillText('សន្លឹកបិទបញ្ជីប្រចាំថ្ងៃ (Z-REPORT)', width / 2, y);
        y += 24;

        this.drawDashedLine(ctx, y, width, margin);
        y += 26;

        ctx.font = '16px "Segoe UI", sans-serif';
        this.drawTwoColumns(ctx, 'Z-Report #:', closeData.z_report_number || '', margin, width - margin, y);
        y += 24;
        this.drawTwoColumns(ctx, 'Close Date:', closeData.close_date || '', margin, width - margin, y);
        y += 24;
        this.drawTwoColumns(ctx, 'Opened By:', `${closeData.opened_by_name || 'N/A'} (${closeData.opened_by_role || 'Cashier'})`, margin, width - margin, y);
        y += 24;
        this.drawTwoColumns(ctx, 'Closed By:', `${closeData.closed_by_name || closeData.manager_name || 'N/A'} (${closeData.closed_by_role || 'Cashier'})`, margin, width - margin, y);
        y += 24;
        const clsTime = closeData.closed_at ? new Date(closeData.closed_at.replace(/-/g, '/')).toLocaleString() : new Date().toLocaleString();
        this.drawTwoColumns(ctx, 'Closed At:', clsTime, margin, width - margin, y);
        y += 24;
        this.drawTwoColumns(ctx, 'Exchange Rate:', `$1.00 = ${(closeData.exchange_rate || 4100).toLocaleString()} ៛`, margin, width - margin, y);
        y += 26;

        if (closeData.needs_manager_review) {
            ctx.strokeRect(margin, y, width - (margin * 2), 40);
            ctx.font = 'bold 15px "Segoe UI", sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText('** NEEDS MANAGER REVIEW **', width / 2, y + 24);
            y += 50;
        }

        // If day was reopened previously, print warning notice!
        if (closeData.reopen_history && closeData.reopen_history.length > 0) {
            ctx.strokeRect(margin, y, width - (margin * 2), 52);
            ctx.font = 'bold 15px "Segoe UI", sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText('** ATTENTION: DAY WAS REOPENED **', width / 2, y + 22);
            const lastReopen = closeData.reopen_history[closeData.reopen_history.length - 1];
            ctx.font = '13px "Segoe UI", sans-serif';
            ctx.fillText(`Reason: ${lastReopen.reason || 'Manager Override'}`, width / 2, y + 42);
            y += 66;
        }

        this.drawDashedLine(ctx, y, width, margin);
        y += 24;

        // Sales overview
        ctx.font = 'bold 16px "Segoe UI", sans-serif';
        ctx.textAlign = 'left';
        ctx.fillText('DAY SALES OVERVIEW', margin, y);
        y += 24;

        ctx.font = '16px "Segoe UI", sans-serif';
        this.drawTwoColumns(ctx, 'Paid Orders:', `${closeData.paid_orders_count || 0}`, margin, width - margin, y);
        y += 22;
        this.drawTwoColumns(ctx, `Cash Sales (${closeData.cash_sales_count || 0}):`, `$${parseFloat(closeData.cash_sales_usd || 0).toFixed(2)}`, margin, width - margin, y);
        y += 22;
        this.drawTwoColumns(ctx, 'ABA Pay:', `$${parseFloat(closeData.aba_sales_usd || 0).toFixed(2)}`, margin, width - margin, y);
        y += 22;
        this.drawTwoColumns(ctx, 'KHQR Pay:', `$${parseFloat(closeData.khqr_sales_usd || 0).toFixed(2)}`, margin, width - margin, y);
        y += 22;
        this.drawTwoColumns(ctx, `Total Bank (${closeData.bank_sales_count || 0}):`, `$${parseFloat(closeData.bank_sales_usd || 0).toFixed(2)}`, margin, width - margin, y);
        y += 22;
        if (parseFloat(closeData.discount_total_usd || 0) > 0) {
            this.drawTwoColumns(ctx, 'Total Discounts:', `-$${parseFloat(closeData.discount_total_usd).toFixed(2)}`, margin, width - margin, y);
            y += 22;
        }
        if (parseInt(closeData.voids_count || 0) > 0) {
            this.drawTwoColumns(ctx, `Voids / Cancelled (${closeData.voids_count}):`, `$${parseFloat(closeData.voids_total_usd || 0).toFixed(2)}`, margin, width - margin, y);
            y += 22;
        }
        if (parseFloat(closeData.cash_refunds_usd || 0) > 0) {
            this.drawTwoColumns(ctx, 'Refunds Paid:', `-$${parseFloat(closeData.cash_refunds_usd).toFixed(2)}`, margin, width - margin, y);
            y += 22;
        }

        this.drawSolidLine(ctx, y, width, margin);
        y += 26;

        ctx.font = 'bold 19px "Segoe UI", sans-serif';
        this.drawTwoColumns(ctx, 'TOTAL DAY SALES:', `$${parseFloat(closeData.total_sales_usd || 0).toFixed(2)}`, margin, width - margin, y);
        y += 24;
        ctx.font = '14px "Segoe UI", sans-serif';
        this.drawTwoColumns(ctx, 'Total Sales (KHR):', `${parseInt(closeData.total_sales_khr || 0).toLocaleString()} ៛`, margin, width - margin, y);
        y += 26;

        this.drawDashedLine(ctx, y, width, margin);
        y += 24;

        // Drawer Consolidation Table
        ctx.font = 'bold 16px "Segoe UI", sans-serif';
        ctx.textAlign = 'left';
        ctx.fillText('DRAWER CONSOLIDATION (WHOLE DAY)', margin, y);
        y += 24;

        ctx.font = '15px "Segoe UI", sans-serif';
        this.drawTwoColumns(ctx, 'Opening Float:', `$${parseFloat(closeData.opening_cash_usd || 0).toFixed(2)} / ${parseInt(closeData.opening_cash_khr || 0).toLocaleString()}៛`, margin, width - margin, y);
        y += 22;
        this.drawTwoColumns(ctx, 'Cash Received:', `+$${parseFloat(closeData.cash_received_usd || 0).toFixed(2)} / +${parseInt(closeData.cash_received_khr || 0).toLocaleString()}៛`, margin, width - margin, y);
        y += 22;
        this.drawTwoColumns(ctx, 'Change Given:', `-$${parseFloat(closeData.change_given_usd || 0).toFixed(2)} / -${parseInt(closeData.change_given_khr || 0).toLocaleString()}៛`, margin, width - margin, y);
        y += 22;
        this.drawTwoColumns(ctx, 'Cash In:', `+$${parseFloat(closeData.cash_in_usd || 0).toFixed(2)} / +${parseInt(closeData.cash_in_khr || 0).toLocaleString()}៛`, margin, width - margin, y);
        y += 22;
        this.drawTwoColumns(ctx, 'Cash Out / Expenses:', `-$${parseFloat(closeData.cash_out_usd || 0).toFixed(2)} / -${parseInt(closeData.cash_out_khr || 0).toLocaleString()}៛`, margin, width - margin, y);
        y += 22;
        this.drawTwoColumns(ctx, 'Cash Refunds:', `-$${parseFloat(closeData.cash_refunds_usd || 0).toFixed(2)} / -${parseInt(closeData.cash_refunds_khr || 0).toLocaleString()}៛`, margin, width - margin, y);
        y += 24;

        this.drawSolidLine(ctx, y, width, margin);
        y += 24;

        ctx.font = 'bold 16px "Segoe UI", sans-serif';
        this.drawTwoColumns(ctx, 'Expected Cash:', `$${parseFloat(closeData.expected_cash_usd || 0).toFixed(2)} / ${parseInt(closeData.expected_cash_khr || 0).toLocaleString()}៛`, margin, width - margin, y);
        y += 24;
        this.drawTwoColumns(ctx, 'Counted Cash:', `$${parseFloat(closeData.counted_cash_usd || 0).toFixed(2)} / ${parseInt(closeData.counted_cash_khr || 0).toLocaleString()}៛`, margin, width - margin, y);
        y += 26;

        this.drawDashedLine(ctx, y, width, margin);
        y += 26;

        const dDiffUsd = parseFloat(closeData.difference_usd || 0);
        const dDiffKhr = parseInt(closeData.difference_khr || 0);
        const dTotDiffUsd = parseFloat(closeData.total_difference_usd || 0);

        ctx.font = 'bold 16px "Segoe UI", sans-serif';
        this.drawTwoColumns(ctx, 'Difference (USD):', `${dDiffUsd > 0 ? '+' : ''}$${dDiffUsd.toFixed(2)} [${(closeData.diff_usd_status || 'EXACT').toUpperCase()}]`, margin, width - margin, y);
        y += 24;
        this.drawTwoColumns(ctx, 'Difference (KHR):', `${dDiffKhr > 0 ? '+' : ''}${dDiffKhr.toLocaleString()}៛ [${(closeData.diff_khr_status || 'EXACT').toUpperCase()}]`, margin, width - margin, y);
        y += 28;

        this.drawSolidLine(ctx, y, width, margin);
        y += 28;

        ctx.font = 'bold 19px "Segoe UI", sans-serif';
        this.drawTwoColumns(ctx, 'WHOLE DAY DIFFERENCE:', `${dTotDiffUsd > 0 ? '+' : ''}$${dTotDiffUsd.toFixed(2)} [${(closeData.total_diff_status || 'EXACT').toUpperCase()}]`, margin, width - margin, y);
        y += 35;

        // Shifts Breakdown List
        if (closeData.shifts && closeData.shifts.length > 0) {
            this.drawDashedLine(ctx, y, width, margin);
            y += 24;
            ctx.font = 'bold 16px "Segoe UI", sans-serif';
            ctx.textAlign = 'left';
            ctx.fillText(`SHIFTS BREAKDOWN (${closeData.shifts.length})`, margin, y);
            y += 24;

            ctx.font = '14px "Segoe UI", sans-serif';
            closeData.shifts.forEach((sh, idx) => {
                this.drawTwoColumns(ctx, `${idx + 1}. ${sh.cashier_name} (${sh.shift_uid})`, `Diff: ${sh.total_diff_usd > 0 ? '+' : ''}$${parseFloat(sh.total_diff_usd || 0).toFixed(2)}`, margin, width - margin, y);
                y += 20;
            });
            y += 10;
        }

        // Manager Sign-Off
        this.drawDashedLine(ctx, y, width, margin);
        y += 45;

        ctx.font = 'bold 15px "Segoe UI", sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText(`Closed By: ${closeData.closed_by_name || closeData.manager_name || 'Staff'} (${closeData.closed_by_role || 'Cashier'})`, width / 2, y);
        y += 26;

        if (closeData.needs_manager_review) {
            ctx.fillText('Manager Sign-Off: ____________________', width / 2, y);
            y += 26;
        }

        ctx.font = '13px "Segoe UI", sans-serif';
        ctx.fillText(`Printed on ${new Date().toLocaleString()} | Counter POS`, width / 2, y);
        y += 70;

        return this.cropCanvasHeight(canvas, y);
    }

    /**
     * Dispatch Printing for Open Float Slip
     */
    static async printOpenShiftSlip(shiftData) {
        const config = PosConfig.get();
        if (!config.printerDirectEnabled) {
            window.open(`../views/cashier/slip.php?type=open&id=${shiftData.shift_id}&autoprint=1`, '_blank', 'width=420,height=600');
            return { success: true, fallback: true };
        }

        try {
            const canvas = await this.renderOpenShiftSlipToCanvas(shiftData);
            const bytes = this.canvasToEscPos(canvas, { cut: true, drawer: false });
            return await this.sendPayload(bytes);
        } catch (err) {
            console.warn('Direct print open shift slip failed, falling back to browser window:', err);
            window.open(`../views/cashier/slip.php?type=open&id=${shiftData.shift_id}&autoprint=1`, '_blank', 'width=420,height=600');
            return { success: false, fallback: true, error: err.message };
        }
    }

    /**
     * Dispatch Printing for End Shift Slip
     */
    static async printEndShiftSlip(shiftData) {
        const config = PosConfig.get();
        if (!config.printerDirectEnabled) {
            window.open(`../views/cashier/slip.php?type=end&id=${shiftData.shift_id}&autoprint=1`, '_blank', 'width=420,height=600');
            return { success: true, fallback: true };
        }

        try {
            const canvas = await this.renderEndShiftSlipToCanvas(shiftData);
            const bytes = this.canvasToEscPos(canvas, { cut: true, drawer: true });
            return await this.sendPayload(bytes);
        } catch (err) {
            console.warn('Direct print end shift slip failed, falling back to browser window:', err);
            window.open(`../views/cashier/slip.php?type=end&id=${shiftData.shift_id}&autoprint=1`, '_blank', 'width=420,height=600');
            return { success: false, fallback: true, error: err.message };
        }
    }

    /**
     * Dispatch Printing for Close Day Slip (Z-Report)
     */
    static async printCloseDaySlip(closeData) {
        const config = PosConfig.get();
        const closeRef = closeData.close_id || closeData.z_report_number || closeData.close_date;
        if (!config.printerDirectEnabled) {
            window.open(`../views/cashier/slip.php?type=close_day&id=${closeRef}&autoprint=1`, '_blank', 'width=420,height=600');
            return { success: true, fallback: true };
        }

        try {
            const canvas = await this.renderCloseDaySlipToCanvas(closeData);
            const bytes = this.canvasToEscPos(canvas, { cut: true, drawer: false });
            return await this.sendPayload(bytes);
        } catch (err) {
            console.warn('Direct print close day slip failed, falling back to browser window:', err);
            window.open(`../views/cashier/slip.php?type=close_day&id=${closeRef}&autoprint=1`, '_blank', 'width=420,height=600');
            return { success: false, fallback: true, error: err.message };
        }
    }

    /**
     * Render Cash Movement Slip (Cash In / Cash Out Voucher)
     */
    static async renderCashMovementSlipToCanvas(movementData) {
        if (typeof document !== 'undefined' && document.fonts && document.fonts.ready) {
            try { await document.fonts.ready; } catch (e) {}
        }

        const width = this.DOT_WIDTH; // 576 dots
        const estHeight = 720;
        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = estHeight;
        const ctx = canvas.getContext('2d');

        ctx.fillStyle = '#FFFFFF';
        ctx.fillRect(0, 0, width, estHeight);
        ctx.fillStyle = '#000000';

        const margin = 30;
        let y = 30;

        const isCashIn = (movementData.type || '').toLowerCase() === 'cash_in';
        const shop = movementData.shop || {};
        const shopKm = shop.shop_name_km || 'សេវាកម្មលាងរថយន្ត និងម៉ូតូ';
        const shopEn = shop.shop_name_en || 'My Car Wash';
        const shopPhone = shop.shop_phone || '012 334 556';
        const shopAddr = shop.shop_address || 'Phnom Penh, Cambodia';

        // Shop header
        ctx.textAlign = 'center';
        ctx.font = `bold 22px ${this.KHMER_FONT_STACK}`;
        ctx.fillText(shopKm, width / 2, y);
        y += 28;
        ctx.font = 'bold 18px "Segoe UI", sans-serif';
        ctx.fillText(shopEn, width / 2, y);
        y += 22;
        ctx.font = `14px ${this.KHMER_FONT_STACK}`;
        ctx.fillText(shopAddr, width / 2, y);
        y += 20;
        ctx.fillText(`Tel: ${shopPhone}`, width / 2, y);
        y += 24;

        this.drawSolidLine(ctx, y, width, margin);
        y += 24;

        // Title
        ctx.font = 'bold 22px "Segoe UI", sans-serif';
        ctx.fillText(isCashIn ? 'CASH IN VOUCHER' : 'CASH OUT / EXPENSE VOUCHER', width / 2, y);
        y += 24;
        ctx.font = `bold 18px ${this.KHMER_FONT_STACK}`;
        ctx.fillText(isCashIn ? 'ប័ណ្ណបង់ប្រាក់ចូលថត' : 'ប័ណ្ណដកប្រាក់ចំណាយ', width / 2, y);
        y += 24;

        this.drawDashedLine(ctx, y, width, margin);
        y += 24;

        // Details
        ctx.font = '16px "Segoe UI", sans-serif';
        if (movementData.event_uid) {
            this.drawTwoColumns(ctx, 'Ref #:', movementData.event_uid, margin, width - margin, y);
            y += 22;
        }
        if (movementData.shift_id) {
            this.drawTwoColumns(ctx, 'Shift ID:', `#${movementData.shift_id}`, margin, width - margin, y);
            y += 22;
        }
        this.drawTwoColumns(ctx, 'Cashier:', movementData.cashier_name || 'Staff', margin, width - margin, y);
        y += 22;
        if (movementData.manager_name) {
            this.drawTwoColumns(ctx, 'Authorized By:', movementData.manager_name, margin, width - margin, y);
            y += 22;
        }
        const timeStr = movementData.created_at ? new Date(movementData.created_at.replace(/-/g, '/')).toLocaleString() : new Date().toLocaleString();
        this.drawTwoColumns(ctx, 'Date & Time:', timeStr, margin, width - margin, y);
        y += 24;

        // Reason
        ctx.textAlign = 'left';
        ctx.font = `15px "Segoe UI", ${this.KHMER_FONT_STACK}`;
        ctx.fillText(`Reason: ${movementData.reason || 'General'}`, margin, y);
        y += 26;

        // Amount Box
        const boxHeight = 60;
        ctx.strokeRect(margin, y, width - (margin * 2), boxHeight);
        ctx.textAlign = 'center';
        ctx.font = 'bold 24px "Segoe UI", sans-serif';
        const usdVal = parseFloat(movementData.amount_usd || 0).toFixed(2);
        const khrVal = parseInt(movementData.amount_khr || 0).toLocaleString();
        let amtStr = `$${usdVal} USD`;
        if (parseInt(movementData.amount_khr || 0) > 0) {
            amtStr += ` / ${khrVal} ៛`;
        }
        ctx.fillText(amtStr, width / 2, y + 38);
        y += boxHeight + 35;

        // Signatures
        ctx.font = '14px "Segoe UI", sans-serif';
        ctx.textAlign = 'left';
        ctx.fillText('Cashier Signature:', margin, y);
        ctx.textAlign = 'right';
        ctx.fillText(isCashIn ? 'Received By:' : 'Manager / Paid To:', width - margin, y);
        y += 40;
        ctx.textAlign = 'left';
        ctx.fillText('___________________', margin, y);
        ctx.textAlign = 'right';
        ctx.fillText('___________________', width - margin, y);
        y += 35;

        // Footer
        ctx.textAlign = 'center';
        ctx.font = '12px "Segoe UI", sans-serif';
        ctx.fillText('POWERED BY GOTPOS', width / 2, y);
        y += 20;

        return this.cropCanvasHeight(canvas, y + 20);
    }

    /**
     * Print Cash Movement Slip Directly
     */
    static async printCashMovementSlip(movementData) {
        const config = PosConfig.get();
        try {
            const canvas = await this.renderCashMovementSlipToCanvas(movementData);
            const isCashOut = (movementData.type || '').toLowerCase() === 'cash_out';
            const bytes = this.canvasToEscPos(canvas, { cut: true, drawer: true });
            return await this.sendPayload(bytes);
        } catch (err) {
            console.warn('Direct print cash movement slip failed:', err);
            return { success: false, error: err.message };
        }
    }

    /**
     * Render Void / Cancelled Slip
     */
    static async renderVoidSlipToCanvas(voidData) {
        if (typeof document !== 'undefined' && document.fonts && document.fonts.ready) {
            try { await document.fonts.ready; } catch (e) {}
        }

        const width = this.DOT_WIDTH;
        const estHeight = 650;
        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = estHeight;
        const ctx = canvas.getContext('2d');

        ctx.fillStyle = '#FFFFFF';
        ctx.fillRect(0, 0, width, estHeight);
        ctx.fillStyle = '#000000';

        const margin = 30;
        let y = 30;

        const shop = voidData.shop || {};
        const shopKm = shop.shop_name_km || 'សេវាកម្មលាងរថយន្ត និងម៉ូតូ';
        const shopEn = shop.shop_name_en || 'My Car Wash';

        ctx.textAlign = 'center';
        ctx.font = `bold 22px ${this.KHMER_FONT_STACK}`;
        ctx.fillText(shopKm, width / 2, y);
        y += 28;
        ctx.font = 'bold 18px "Segoe UI", sans-serif';
        ctx.fillText(shopEn, width / 2, y);
        y += 24;

        this.drawSolidLine(ctx, y, width, margin);
        y += 24;

        // Void Title
        ctx.font = 'bold 22px "Segoe UI", sans-serif';
        ctx.fillText('*** VOID / CANCELLED RECEIPT ***', width / 2, y);
        y += 24;
        ctx.font = `bold 18px ${this.KHMER_FONT_STACK}`;
        ctx.fillText('*** ប័ណ្ណលុបចោល ***', width / 2, y);
        y += 24;

        this.drawDashedLine(ctx, y, width, margin);
        y += 24;

        ctx.font = '16px "Segoe UI", sans-serif';
        this.drawTwoColumns(ctx, 'Order #:', voidData.order_number || '', margin, width - margin, y);
        y += 22;
        if (voidData.plate_number) {
            this.drawTwoColumns(ctx, 'Plate #:', voidData.plate_number, margin, width - margin, y);
            y += 22;
        }
        if (voidData.shift_id) {
            this.drawTwoColumns(ctx, 'Shift ID:', `#${voidData.shift_id}`, margin, width - margin, y);
            y += 22;
        }
        this.drawTwoColumns(ctx, 'Cashier:', voidData.cashier_name || 'Staff', margin, width - margin, y);
        y += 22;
        this.drawTwoColumns(ctx, 'Manager Approver:', voidData.manager_name || 'Manager', margin, width - margin, y);
        y += 22;
        const timeStr = voidData.cancelled_at ? new Date(voidData.cancelled_at.replace(/-/g, '/')).toLocaleString() : new Date().toLocaleString();
        this.drawTwoColumns(ctx, 'Voided At:', timeStr, margin, width - margin, y);
        y += 24;

        ctx.textAlign = 'left';
        ctx.font = `15px "Segoe UI", ${this.KHMER_FONT_STACK}`;
        ctx.fillText(`Reason: ${voidData.reason || 'Customer requested cancel'}`, margin, y);
        y += 26;

        // Boxed Total Voided
        const boxHeight = 56;
        ctx.strokeRect(margin, y, width - (margin * 2), boxHeight);
        ctx.textAlign = 'center';
        ctx.font = 'bold 22px "Segoe UI", sans-serif';
        const usdVal = parseFloat(voidData.total_usd || 0).toFixed(2);
        ctx.fillText(`VOIDED TOTAL: $${usdVal} USD`, width / 2, y + 36);
        y += boxHeight + 35;

        // Signatures
        ctx.font = '14px "Segoe UI", sans-serif';
        ctx.textAlign = 'left';
        ctx.fillText('Cashier Signature:', margin, y);
        ctx.textAlign = 'right';
        ctx.fillText('Manager Sign-off:', width - margin, y);
        y += 40;
        ctx.textAlign = 'left';
        ctx.fillText('___________________', margin, y);
        ctx.textAlign = 'right';
        ctx.fillText('___________________', width - margin, y);
        y += 35;

        ctx.textAlign = 'center';
        ctx.font = '12px "Segoe UI", sans-serif';
        ctx.fillText('POWERED BY GOTPOS', width / 2, y);
        y += 20;

        return this.cropCanvasHeight(canvas, y + 20);
    }

    /**
     * Print Void Slip Directly
     */
    static async printVoidSlip(voidData) {
        try {
            const canvas = await this.renderVoidSlipToCanvas(voidData);
            const bytes = this.canvasToEscPos(canvas, { cut: true, drawer: false });
            return await this.sendPayload(bytes);
        } catch (err) {
            console.warn('Direct print void slip failed:', err);
            return { success: false, error: err.message };
        }
    }
}

// Expose globally
window.PosThermalPrinter = PosThermalPrinter;
