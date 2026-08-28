/**
 * Manual dispatch form on Courier Settings page.
 */
(function () {
    'use strict';

    var form = document.getElementById('manual-dispatch-form');
    if (!form) return;

    var config = window.__COURIER_DISPATCH__ || {};
    var csrf = config.csrf || '';
    var orderIdInput = document.getElementById('dispatch-order-id');
    var orderIdVisible = document.getElementById('dispatch-order-id-visible');
    var orderLabel = document.getElementById('dispatch-order-label');

    function setOrderId(id, customer) {
        if (orderIdInput) orderIdInput.value = String(id);
        if (orderIdVisible) orderIdVisible.value = String(id);
        if (orderLabel) {
            orderLabel.textContent = customer
                ? 'Shipping order #' + id + ' — ' + customer
                : 'Shipping order #' + id;
        }
    }

    document.querySelectorAll('.dispatch-order-pick').forEach(function (btn) {
        btn.addEventListener('click', function () {
            setOrderId(
                parseInt(btn.getAttribute('data-order-id'), 10),
                btn.getAttribute('data-customer') || ''
            );
            document.querySelectorAll('.dispatch-order-pick').forEach(function (b) {
                b.classList.remove('border-primary', 'bg-surface-container');
            });
            btn.classList.add('border-primary', 'bg-surface-container');
        });
    });

    if (orderIdVisible) {
        orderIdVisible.addEventListener('change', function () {
            var id = parseInt(orderIdVisible.value, 10);
            if (id > 0) setOrderId(id, '');
        });
    }

    function maybeAutoTrackingUrl() {
        var courierEl = document.getElementById('dispatch-courier');
        var trackingEl = document.getElementById('dispatch-tracking');
        var urlEl = document.getElementById('dispatch-tracking-url');
        if (!courierEl || !trackingEl || !urlEl || urlEl.value.trim() !== '') return;

        var courier = courierEl.value.trim();
        var tracking = trackingEl.value.trim();
        if (courier === '' || tracking === '') return;

        fetch('/api/shipment.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                csrf_token: csrf,
                action: 'tracking_url',
                courier_name: courier,
                tracking_number: tracking
            })
        }).then(function (r) { return r.json(); }).then(function (data) {
            if (data.success && data.tracking_url && urlEl.value.trim() === '') {
                urlEl.value = data.tracking_url;
            }
        }).catch(function () {});
    }

    form.querySelectorAll('[name="courier_name"], [name="tracking_number"]').forEach(function (el) {
        el.addEventListener('blur', maybeAutoTrackingUrl);
    });

    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        var orderId = orderIdInput ? parseInt(orderIdInput.value, 10) : 0;
        if (!orderId && orderIdVisible) {
            orderId = parseInt(orderIdVisible.value, 10);
        }
        if (!orderId) {
            alert('Select or enter an order ID.');
            return;
        }

        var fd = new FormData(form);
        fd.set('order_id', String(orderId));
        fd.delete('order_id_visible');
        fd.append('csrf_token', csrf);
        fd.append('action', 'create');

        var btn = form.querySelector('[type="submit"]');
        if (btn) btn.disabled = true;

        try {
            var res = await fetch('/api/shipment.php', { method: 'POST', body: fd });
            var data = await res.json();
            if (!data.success) {
                alert(data.error || 'Could not save shipment');
                return;
            }
            alert('Shipment saved — customer notified on WhatsApp.');
            window.location.reload();
        } catch (err) {
            alert('Network error');
        } finally {
            if (btn) btn.disabled = false;
        }
    });
})();
