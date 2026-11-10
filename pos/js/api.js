/**
 * POS API Client Bridge
 * Communicates with the Back Office POS endpoint.
 * Includes network error handling and automatic device authentication.
 */
class PosApi {
    static async request(action, payload = null, options = {}) {
        const config = PosConfig.get();
        const endpoint = PosConfig.getApiEndpoint();
        const deviceKey = config.deviceKey;

        const headers = {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-Device-Key': deviceKey
        };

        if (options.idempotencyKey) {
            headers['X-Idempotency-Key'] = options.idempotencyKey;
        }

        // Attach verified staff session token on every request except staff_login
        if (action !== 'staff_login' && typeof PosStorage !== 'undefined') {
            const sessionToken = PosStorage.getSessionToken();
            if (sessionToken) {
                headers['X-Staff-Token'] = sessionToken;
            }
        }

        const bodyData = payload ? { action, ...payload } : { action };
        if (options.idempotencyKey) {
            bodyData.idempotency_key = options.idempotencyKey;
        }

        // Stop sending client-controlled staff_id / user_id / cashier_id / staff_role
        if (action !== 'staff_login') {
            delete bodyData.staff_id;
            delete bodyData.user_id;
            delete bodyData.cashier_id;
            delete bodyData.staff_role;
            delete bodyData.user_role;
        }

        const fetchOptions = {
            method: 'POST',
            credentials: 'include',
            headers,
            body: JSON.stringify(bodyData)
        };

        try {
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 12000); // 12s timeout
            fetchOptions.signal = controller.signal;

            const response = await fetch(endpoint, fetchOptions);
            clearTimeout(timeoutId);

            // Capture refreshed or bridged X-Staff-Token from server header
            const respStaffToken = response.headers ? response.headers.get('X-Staff-Token') : null;
            if (respStaffToken && typeof PosStorage !== 'undefined') {
                PosStorage.setSessionToken(respStaffToken);
            }

            const json = await response.json().catch(() => null);

            if (!response.ok) {
                const errMsg = json?.message || json?.error || `Server returned error (${response.status})`;
                const err = new Error(errMsg);
                err.status = response.status;
                err.code = json?.error || 'SERVER_ERROR';
                err.data = json;

                // On 401 SESSION_INVALID, inform app to handle session expiration cleanly
                if (response.status === 401 && (json?.error === 'SESSION_INVALID' || json?.error === 'STAFF_REQUIRED')) {
                    if (typeof PosApp !== 'undefined' && typeof PosApp.handleSessionInvalid === 'function') {
                        PosApp.handleSessionInvalid(json?.message || errMsg);
                    }
                }

                throw err;
            }

            if (json && json.success === false) {
                const errMsg = json.message || json.error || 'Server rejected action';
                const err = new Error(errMsg);
                err.status = 400;
                err.code = json.error || 'SERVER_REJECTED';
                err.data = json;
                throw err;
            }

            return json;
        } catch (err) {
            // Mark network status as offline if connection refused or timed out
            if (err.name === 'AbortError' || err.message.includes('Failed to fetch') || err.message.includes('NetworkError')) {
                const networkErr = new Error('NETWORK_OFFLINE');
                networkErr.isOffline = true;
                networkErr.originalMessage = err.message;
                throw networkErr;
            }
            throw err;
        }
    }

    /**
     * Test connection to Back Office with optional custom credentials
     */
    static async testConnection(customConfig = null) {
        const config = customConfig ? { ...PosConfig.get(), ...customConfig } : PosConfig.get();
        const base = (config.backofficeUrl || '').replace(/\/+$/, '');
        const endpoint = `${base}/api/pos/index.php`;
        const deviceKey = config.deviceKey || '';

        try {
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 8000); // 8s timeout

            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Device-Key': deviceKey
                },
                body: JSON.stringify({ action: 'sync_catalog' }),
                signal: controller.signal
            });
            clearTimeout(timeoutId);

            const json = await response.json().catch(() => null);

            if (!response.ok) {
                let reason = json?.message || json?.error || `Server returned HTTP ${response.status}`;
                if (response.status === 401 || json?.error === 'DEVICE_KEY_REQUIRED') {
                    reason = 'Device API Key is required or invalid.';
                } else if (response.status === 403) {
                    if (json?.error === 'DEVICE_BLOCKED') {
                        reason = 'This POS device has been blocked by the Back Office Owner.';
                    } else {
                        reason = 'Invalid Device API Key: This device is not registered in the Back Office.';
                    }
                } else if (response.status === 404) {
                    reason = `Endpoint not found (404) at ${endpoint}. Check the Back Office address.`;
                }
                return { success: false, error: reason, status: response.status, data: json };
            }

            if (json && json.success) {
                return {
                    success: true,
                    device: json.device,
                    settings: json.settings,
                    exchange_rate: json.exchange_rate
                };
            } else {
                return {
                    success: false,
                    error: json?.message || json?.error || 'Server did not return a valid catalog'
                };
            }
        } catch (err) {
            let reason = err.message || 'Cannot reach Back Office';
            if (err.name === 'AbortError') {
                reason = `Connection timed out (8s). Server at "${base}" did not respond.`;
            } else if (err.message && (err.message.includes('Failed to fetch') || err.message.includes('NetworkError'))) {
                reason = `Cannot reach server at "${base}". Please verify the URL and ensure the Back Office server is running.`;
            }
            return {
                success: false,
                isOffline: true,
                error: reason
            };
        }
    }

    /**
     * Bridge active Back Office web session (cookie) to POS staff session token
     */
    static async getWebSession() {
        return await this.request('get_web_session');
    }

    /**
     * Staff Login with Username and 6-digit PIN
     */
    static async staffLogin(username, pin) {
        return await this.request('staff_login', {
            username: (username || '').trim(),
            pin: String(pin || '').trim(),
            password: String(pin || '').trim()
        });
    }

    /**
     * Terminate active staff session
     */
    static async staffLogout() {
        try {
            return await this.request('staff_logout');
        } catch (e) {
            console.warn('staffLogout error (ignored):', e);
            return { success: true };
        }
    }

    /**
     * Synchronize Services, Pricing Matrix, Products, Crew, and Settings
     */
    static async syncCatalog() {
        const data = await this.request('sync_catalog');
        if (data.success) {
            PosStorage.setCatalog(data, data.synced_at || new Date().toISOString());
            const mergedSettings = { ...(data.settings || {}), ...(data.shop || {}) };
            PosStorage.setSettings(mergedSettings);
            if (data.exchange_rate) {
                PosConfig.save({ exchangeRate: parseInt(data.exchange_rate) });
            }
        }
        return data;
    }

    /**
     * Synchronize Customers & Vehicles list
     */
    static async syncCustomers() {
        const data = await this.request('sync_customers');
        if (data.success) {
            PosStorage.setCustomers(data);
        }
        return data;
    }

    /**
     * Quick register Customer and Vehicle
     */
    static async addCustomerVehicle(custData) {
        return await this.request('add_customer_vehicle', custData);
    }

    /**
     * Submit Order with Idempotency Protection
     */
    static async createOrder(orderData, idempotencyKey) {
        return await this.request('create_order', orderData, { idempotencyKey });
    }

    static async markReceiptPrinted(orderId, orderNumber = '') {
        return await this.request('mark_receipt_printed', { order_id: orderId, order_number: orderNumber });
    }

    /**
     * Acknowledge receipt of electronic receipt payload from server
     */
    static async ackReceiptIssued(orderId, orderNumber = '') {
        return await this.request('ack_receipt_issued', { order_id: orderId, order_number: orderNumber });
    }

    /**
     * Update job status in Back Office (waiting, washing, working, finished, paid, cancelled)
     */
    static async syncOrderStatus(orderId, orderNumber, status, paymentMethod = 'cash', paymentDetails = {}) {
        return await this.request('sync_order_status', {
            order_id: orderId,
            order_number: orderNumber,
            status,
            payment_method: paymentMethod,
            amount_tendered_usd: paymentDetails.amount_tendered_usd,
            change_usd: paymentDetails.change_usd,
            payment_ref: paymentDetails.payment_ref,
            manager_pin: paymentDetails.manager_pin,
            manager_username: paymentDetails.manager_username,
            override_reason: paymentDetails.override_reason || paymentDetails.reason
        });
    }

    /**
     * Fetch Live Queue Orders (all, or filtered by washer/mechanic)
     */
    static async getQueue(role = null, userId = null) {
        return await this.request('get_queue', { role, user_id: userId });
    }

    /**
     * Fetch Today's Orders with details for receipt reprinting and search
     */
    static async getTodayOrders(search = '', date = null) {
        return await this.request('get_today_orders', { search, date });
    }

    /**
     * Fetch Single Order with all items, crew, and details for Reprint
     */
    static async getOrder(orderNumber = '', orderId = 0) {
        return await this.request('get_order', { order_number: orderNumber, order_id: orderId });
    }

    /**
     * Cancel Order
     */
    static async cancelOrder(orderId, orderNumber, reason = 'Cancelled by staff', managerUsername = '', managerPin = '') {
        const payload = {
            order_id: orderId,
            order_number: orderNumber,
            reason
        };
        if (managerUsername) payload.manager_username = managerUsername;
        if (managerPin) payload.manager_pin = managerPin;
        return await this.request('cancel_order', payload);
    }

    /**
     * Save / Update Repair Notes
     */
    static async saveRepairNote(orderId, orderNumber, repairNotes) {
        return await this.request('save_repair_note', {
            order_id: orderId,
            order_number: orderNumber,
            repair_notes: repairNotes
        });
    }

    /**
     * Change Washers and Mechanics assigned to an order
     */
    static async assignCrew(orderId, orderNumber, washers = [], mechanics = []) {
        return await this.request('assign_crew', {
            order_id: orderId,
            order_number: orderNumber,
            washers,
            mechanics
        });
    }

    /**
     * Get Live Bays and Waiting Queues (Bays 1-4 and FIFO Waiting Queue)
     */
    static async getBaysAndQueue() {
        return await this.request('get_bays_and_queue');
    }

    /**
     * Mark Customer Arrived so car joins waiting queue
     */
    static async markCustomerArrived(orderId, plateNumber = '', staffId = null) {
        const user = PosStorage.getUser();
        const effectiveStaffId = staffId || user?.id || 1;
        return await this.request('customer_arrived', {
            order_id: orderId,
            plate_number: plateNumber,
            staff_id: effectiveStaffId
        });
    }

    /**
     * Assign Car to Bay with Back Office Concurrency Lock
     */
    static async assignBay(bayId, orderId, extra = {}) {
        const user = PosStorage.getUser();
        let payload = {
            bay_id: bayId,
            order_id: orderId,
            staff_id: user?.id || 1
        };
        if (typeof extra === 'number') {
            payload.staff_id = extra;
        } else if (extra && typeof extra === 'object') {
            payload = { ...payload, ...extra };
        }
        return await this.request('assign_bay', payload);
    }

    /**
     * Finish Car in Bay: Frees the bay and marks order "Ready to pay"
     */
    static async finishCar(bayId, orderId = null, staffId = null) {
        const user = PosStorage.getUser();
        const effectiveStaffId = staffId || user?.id || 1;
        return await this.request('finish_car', {
            bay_id: bayId,
            order_id: orderId,
            staff_id: effectiveStaffId
        });
    }

    /**
     * Get Daily Totals Report from Central System
     */
    static async getDailyReport(date = null, posDeviceId = null) {
        return await this.request('get_daily_report', { date, pos_device_id: posDeviceId });
    }

    /**
     * Save / Submit Shift / Daily Close
     */
    static async closeDay(date, countedCashUsd, countedCashKhr, notes = '', userId = null, userName = '', userRole = '') {
        return await this.request('close_day', {
            date,
            counted_cash_usd: countedCashUsd,
            counted_cash_khr: countedCashKhr,
            notes,
            user_id: userId,
            user_name: userName,
            user_role: userRole
        });
    }

    /**
     * Reopen Daily Close (Manager / Owner only)
     */
    static async reopenDailyClose(date, staffRole = '', userId = null) {
        return await this.request('reopen_daily_close', {
            date,
            staff_role: staffRole,
            user_id: userId
        });
    }

    /**
     * Check if day / shift is closed
     */
    static async getDailyCloseStatus(date = null) {
        return await this.request('get_daily_close_status', { date });
    }

    /**
     * Get Oil Due Soon Reminders List
     */
    static async getOilReminders(filter = 'all', search = '') {
        return await this.request('get_oil_reminders', { filter, search });
    }

    /**
     * Record Oil Follow-up Action (Contacted, Snooze 7d, Snooze 30d)
     */
    static async updateOilContact(vehicleId, actionType = 'contacted', notes = '') {
        return await this.request('update_oil_contact', {
            vehicle_id: vehicleId,
            action_type: actionType,
            notes
        });
    }

    /**
     * Cashier Control: Get active shift status for cashier
     */
    static async getShiftStatus(cashierId = null) {
        const user = PosStorage.getUser();
        const cid = cashierId || user?.id || 0;
        return await this.request('get_shift_status', { cashier_id: cid });
    }

    /**
     * Cashier Control: Start a new shift
     */
    static async startShift(data) {
        return await this.request('shift_start', {
            cashier_id: data.cashierId,
            cashier_name: data.cashierName,
            opening_cash_usd: data.openingCashUsd,
            opening_cash_khr: data.openingCashKhr,
            notes: data.notes || ''
        });
    }

    /**
     * Cashier Control: End shift with blind count and next cashier handover sign-off PIN
     */
    static async endShift(data) {
        return await this.request('shift_end', {
            shift_id: data.shiftId,
            counted_cash_usd: data.countedCashUsd,
            counted_cash_khr: data.countedCashKhr,
            outgoing_pin: data.outgoingPin,
            incoming_username: data.incomingUsername,
            incoming_pin: data.incomingPin,
            notes: data.notes || ''
        });
    }

    /**
     * Cashier Control: Verify Manager PIN
     */
    static async verifyManagerPin(managerUsername, managerPin) {
        return await this.request('verify_manager_pin', {
            manager_username: managerUsername,
            manager_pin: managerPin
        });
    }

    /**
     * Bank Transfer: Submit ABA / KHQR payment with slip photo
     */
    static async submitBankTransfer(data) {
        return await this.request('submit_bank_transfer', {
            order_id: data.orderId,
            payment_method: data.paymentMethod,
            payment_ref: data.paymentRef || '',
            slip_base64: data.slipBase64 || null,
            tendered_usd: data.tenderedUsd || 0.00,
            discount_usd: data.discountUsd || 0.00,
            points_to_redeem: data.pointsToRedeem || 0,
            cashier_id: data.cashierId || null
        });
    }

    /**
     * Bank Transfer: Manager approves transfer after checking bank app
     */
    static async approveBankTransfer(orderId, managerUsername, managerPin, notes = '') {
        return await this.request('approve_bank_transfer', {
            order_id: orderId,
            manager_username: managerUsername,
            manager_pin: managerPin,
            notes
        });
    }

    /**
     * Cashier Control: Conduct Spot Audit
     */
    static async conductSpotAudit(data) {
        return await this.request('spot_audit', {
            cashier_id: data.cashierId,
            counted_cash_usd: data.countedCashUsd,
            counted_cash_khr: data.countedCashKhr,
            manager_username: data.managerUsername,
            manager_pin: data.managerPin,
            notes: data.notes || ''
        });
    }

    /**
     * Day Lock Status
     */
    static async getDayLockStatus(date = null) {
        return await this.request('get_day_lock_status', { date });
    }

    /**
     * Manager Close Day with Z-Report
     */
    static async closeDayZReport(managerUsername, managerPin, countedCashUsd, countedCashKhr, notes = '') {
        return await this.request('close_day_zreport', {
            manager_username: managerUsername,
            manager_pin: managerPin,
            counted_cash_usd: countedCashUsd,
            counted_cash_khr: countedCashKhr,
            notes
        });
    }

    /**
     * Process Refund (Manager PIN authorized)
     */
    static async processRefund(data) {
        return await this.request('process_refund', {
            order_id: data.orderId,
            refund_amount_usd: data.refundAmountUsd,
            refund_reason: data.refundReason,
            manager_username: data.managerUsername,
            manager_pin: data.managerPin,
            cashier_id: data.cashierId,
            is_offline: !!data.isOffline
        });
    }

    /**
     * Customer Arrived: Cashier marks vehicle arrived (joins queue)
     */
    static async markCustomerArrived(orderId, plateNumber = '') {
        const user = PosStorage.getUser();
        return await this.request('customer_arrived', {
            order_id: orderId,
            plate_number: plateNumber,
            staff_id: user?.id || null
        });
    }

    /**
     * Live Bays & Queue Summary
     */
    static async getBaysAndQueue() {
        return await this.request('get_bays_and_queue');
    }

    /**
     * Washer Screen: Fetch washer jobs, bays, and queue
     */
    static async getWasherJobs(staffId = null) {
        const user = PosStorage.getUser();
        const sid = staffId || user?.id || null;
        return await this.request('get_washer_jobs', { staff_id: sid });
    }

    /**
     * Finish Wash: Frees bay, handles "took longer", and handshakes mixed orders
     * Accepts explicit (bayId, orderId, tookLonger, reason) or legacy (bayOrOrderId, tookLonger, reason)
     */
    static async finishWasherCar(bayIdOrOrderId, orderIdOrTookLonger = null, tookLonger = null, reason = '') {
        let bId = null;
        let oId = null;
        let tl = null;
        let rsn = '';

        if (tookLonger !== null && typeof tookLonger === 'number') {
            // New signature: finishWasherCar(bayId, orderId, tookLonger, reason)
            bId = (bayIdOrOrderId && bayIdOrOrderId > 0) ? parseInt(bayIdOrOrderId, 10) : null;
            oId = (orderIdOrTookLonger && orderIdOrTookLonger > 0) ? parseInt(orderIdOrTookLonger, 10) : null;
            tl = tookLonger;
            rsn = reason || '';
        } else if (orderIdOrTookLonger !== null && typeof orderIdOrTookLonger === 'number' && orderIdOrTookLonger > 1) {
            // Called with (bayId, orderId)
            bId = (bayIdOrOrderId && bayIdOrOrderId > 0) ? parseInt(bayIdOrOrderId, 10) : null;
            oId = parseInt(orderIdOrTookLonger, 10);
            tl = (tookLonger === 0 || tookLonger === 1) ? tookLonger : null;
            rsn = reason || '';
        } else {
            // Legacy signature: finishWasherCar(orderId, tookLonger, reason)
            oId = (bayIdOrOrderId && bayIdOrOrderId > 0) ? parseInt(bayIdOrOrderId, 10) : null;
            tl = (orderIdOrTookLonger === 0 || orderIdOrTookLonger === 1) ? orderIdOrTookLonger : null;
            rsn = (typeof tookLonger === 'string') ? tookLonger : (reason || '');
        }

        const user = PosStorage.getUser();
        return await this.request('finish_car', {
            bay_id: bId,
            order_id: oId,
            took_longer: tl,
            reason: rsn,
            staff_id: user?.id || null
        });
    }

    /**
     * Technical Screen: Fetch technical jobs, queue, tasks, and checklists
     */
    static async getTechnicalJobs(staffId = null) {
        const user = PosStorage.getUser();
        const sid = staffId || user?.id || null;
        return await this.request('get_technical_jobs', { staff_id: sid });
    }

    /**
     * Technical Handshake: Receive vehicle from wash
     */
    static async receiveFromWash(orderId, bayId = 4) {
        const user = PosStorage.getUser();
        return await this.request('receive_from_wash', {
            order_id: orderId,
            bay_id: bayId,
            staff_id: user?.id || null
        });
    }

    /**
     * Technical: Start task
     */
    static async startTechTask(taskId) {
        const user = PosStorage.getUser();
        return await this.request('start_tech_task', {
            task_id: taskId,
            staff_id: user?.id || null
        });
    }

    /**
     * Technical: Finish task
     */
    static async finishTechTask(taskId, actualOil = null) {
        const user = PosStorage.getUser();
        return await this.request('finish_tech_task', {
            task_id: taskId,
            actual_oil_used: actualOil,
            staff_id: user?.id || null
        });
    }

    /**
     * Technical: Update checklist item
     */
    static async updateChecklistItem(itemId, status, measurementValue = '', photoBase64 = null, notes = '') {
        const user = PosStorage.getUser();
        return await this.request('update_checklist_item', {
            item_id: itemId,
            status,
            measurement_value: measurementValue,
            photo_base64: photoBase64,
            notes,
            staff_id: user?.id || null
        });
    }

    /**
     * Technical: Quick pass all checklist items
     */
    static async passAllInspection(orderId) {
        const user = PosStorage.getUser();
        return await this.request('pass_all_inspection', {
            order_id: orderId,
            staff_id: user?.id || null
        });
    }

    /**
     * Technical: Request parts from warehouse
     */
    static async requestParts(orderId, productId, qty = 1, notes = '', taskId = null) {
        const user = PosStorage.getUser();
        return await this.request('request_parts', {
            order_id: orderId,
            product_id: productId,
            qty,
            notes,
            task_id: taskId,
            staff_id: user?.id || null
        });
    }

    /**
     * Technical: Finish full job order
     */
    static async finishTechJob(orderId, tookLonger = null) {
        const user = PosStorage.getUser();
        return await this.request('finish_tech_job', {
            order_id: orderId,
            took_longer: tookLonger,
            staff_id: user?.id || null
        });
    }

    /**
     * Notifications: Get notifications and unread count
     */
    static async getNotifications(staffId = null) {
        const user = PosStorage.getUser();
        const sid = staffId || user?.id || null;
        return await this.request('get_notifications', { staff_id: sid });
    }

    /**
     * Notifications: Mark single notification read
     */
    static async markNotificationRead(notificationId) {
        const user = PosStorage.getUser();
        return await this.request('mark_notification_read', {
            notification_id: notificationId,
            staff_id: user?.id || null
        });
    }

    /**
     * Notifications: Mark all notifications read
     */
    static async markAllNotificationsRead() {
        const user = PosStorage.getUser();
        return await this.request('mark_all_notifications_read', {
            staff_id: user?.id || null
        });
    }

    /**
     * Requirement 1 & 2: Wait Times - Get estimated wait times from back office
     */
    static async getWaitTimes(cartServiceIds = [], vehicleType = 'small_car') {
        return await this.request('get_wait_times', {
            cart_service_ids: cartServiceIds,
            vehicle_type: vehicleType
        });
    }

    /**
     * Requirement 3: Appointments - Get free time slots for a date
     */
    static async getAppointmentSlots(date = null, serviceId = 0) {
        return await this.request('get_appointment_slots', {
            date: date,
            service_id: serviceId
        });
    }

    /**
     * Requirement 3: Appointments - Get appointments list for a date
     */
    static async getAppointments(date = null) {
        return await this.request('get_appointments', {
            date: date
        });
    }

    /**
     * Requirement 3: Appointments - Book a new appointment
     */
    static async createAppointment(appointmentData) {
        return await this.request('create_appointment', appointmentData);
    }

    /**
     * Requirement 3: Appointments - Mark appointment as Customer Arrived in one tap
     */
    static async appointmentArrived(appointmentId, staffId = null) {
        const user = PosStorage.getUser();
        return await this.request('appointment_arrived', {
            appointment_id: appointmentId,
            staff_id: staffId || user?.id || null
        });
    }

    /**
     * Requirement 4: Vehicle Recognition - Lookup customer profile & history by plate
     */
    static async recognizeVehicle(plateNumber) {
        return await this.request('recognize_vehicle', {
            plate_number: plateNumber
        });
    }

    /**
     * Requirement 1, 2: Check for system updates (prices, users, permissions)
     */
    static async checkSystemUpdates(staffId = null) {
        const user = (typeof PosStorage !== 'undefined') ? PosStorage.getUser() : null;
        const activeStaffId = staffId || user?.id || null;
        return await this.request('check_system_updates', {
            staff_id: activeStaffId
        });
    }

    /**
     * Requirement 2: Upsell prompt - Evaluate cart against back-office rules
     */
    static async evaluateCartUpsell(cart, customerId = null, plateNumber = '', vehicleType = 'small_car') {
        return await this.request('evaluate_cart_upsell', {
            cart: cart,
            customer_id: customerId,
            plate_number: plateNumber,
            vehicle_type: vehicleType
        });
    }
}

window.PosApi = PosApi;
