/**
 * Order kanban + shipment modal (Confirmed → Shipped requires tracking).
 */
(function () {
    'use strict';

    var root = document.querySelector('.orders-kanban');
    if (!root) return;

    var config = window.__ORDERS_KANBAN__ || {};
    var csrf = config.csrf || '';
    var requiresShipment = config.requiresShipment !== false;
    var dragging = null;
    var ghost = null;
    var touchId = null;
    var pendingShipOrderId = null;

    var shipmentModal = document.getElementById('shipment-modal');
    var shipmentForm = document.getElementById('shipment-form');
    var timelineModal = document.getElementById('shipment-timeline-modal');
    var timelineBody = document.getElementById('shipment-timeline-body');

    function columnBody(el) {
        while (el && el !== root) {
            if (el.classList && el.classList.contains('kanban-column__body')) return el;
            el = el.parentElement;
        }
        return null;
    }

    function cardFromEl(el) {
        while (el && el !== root) {
            if (el.classList && el.classList.contains('kanban-card')) return el;
            el = el.parentElement;
        }
        return null;
    }

    function statusFromColumn(body) {
        var col = body.closest('.kanban-column');
        return col ? col.getAttribute('data-status') : null;
    }

    function openShipmentModal(orderId) {
        pendingShipOrderId = orderId;
        var input = document.getElementById('shipment-order-id');
        if (input) input.value = String(orderId);
        if (shipmentModal) {
            shipmentModal.hidden = false;
            shipmentModal.setAttribute('aria-hidden', 'false');
        }
    }

    function closeShipmentModal() {
        pendingShipOrderId = null;
        if (shipmentModal) {
            shipmentModal.hidden = true;
            shipmentModal.setAttribute('aria-hidden', 'true');
        }
        if (shipmentForm) shipmentForm.reset();
    }

    function closeTimelineModal() {
        if (timelineModal) {
            timelineModal.hidden = true;
            timelineModal.setAttribute('aria-hidden', 'true');
        }
        if (timelineBody) timelineBody.innerHTML = '';
    }

    async function moveOrder(orderId, newStatus, card) {
        card.classList.add('kanban-card--busy');
        try {
            var res = await fetch('/api/order-status.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ csrf_token: csrf, order_id: orderId, status: newStatus })
            });
            var data = await res.json();
            if (data.requires_shipment) {
                openShipmentModal(orderId);
                return 'shipment_required';
            }
            if (!data.success) {
                alert(data.error || 'Could not update order');
                return false;
            }
            if (typeof App !== 'undefined') {
                if (data.customer_notified) {
                    App.toast((data.status_label || 'Order') + ' — customer notified on WhatsApp', 'success');
                } else if (data.notify_error) {
                    App.toast('Status updated. WhatsApp: ' + data.notify_error, 'info');
                }
            }
            if (typeof BotSync !== 'undefined') {
                BotSync.publish('orders:changed', {
                    bot_id: config.botId || 0,
                    context: data.context || null,
                    order_id: orderId,
                    status: newStatus,
                });
                if (data.context && data.context.version) {
                    BotSync.lastVersion = data.context.version;
                }
            }
            card.setAttribute('data-status', newStatus);
            return true;
        } catch (e) {
            alert('Network error');
            return false;
        } finally {
            card.classList.remove('kanban-card--busy');
        }
    }

    async function createShipment(formData) {
        formData.append('csrf_token', csrf);
        formData.append('action', 'create');
        if (!formData.has('order_id') && pendingShipOrderId) {
            formData.set('order_id', String(pendingShipOrderId));
        }
        var res = await fetch('/api/shipment.php', {
            method: 'POST',
            body: formData
        });
        return res.json();
    }

    function maybeAutoTrackingUrl() {
        var courierEl = document.getElementById('shipment-courier');
        var trackingEl = shipmentForm ? shipmentForm.querySelector('[name="tracking_number"]') : null;
        var urlEl = document.getElementById('shipment-tracking-url');
        if (!courierEl || !trackingEl || !urlEl || urlEl.value.trim() !== '') {
            return;
        }
        var courier = courierEl.value.trim();
        var tracking = trackingEl.value.trim();
        if (courier === '' || tracking === '') {
            return;
        }
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

    async function loadTimeline(orderId) {
        var res = await fetch('/api/shipment.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: csrf, action: 'get', order_id: orderId })
        });
        return res.json();
    }

    function renderTimeline(events) {
        if (!timelineBody) return;
        if (!events || !events.length) {
            timelineBody.innerHTML = '<p class="text-body-md text-on-surface-variant">No timeline events yet.</p>';
            return;
        }
        var html = '<ol class="shipment-timeline__list">';
        events.forEach(function (ev) {
            html += '<li class="shipment-timeline__item">';
            html += '<span class="shipment-timeline__dot"></span>';
            html += '<div><strong>' + escapeHtml(ev.title || ev.status || '') + '</strong>';
            if (ev.event_at) html += '<p class="text-label-sm text-outline">' + escapeHtml(ev.event_at) + '</p>';
            if (ev.description) html += '<p class="text-body-sm">' + escapeHtml(ev.description) + '</p>';
            html += '</div></li>';
        });
        html += '</ol>';
        timelineBody.innerHTML = html;
    }

    function escapeHtml(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function updateColumnCounts() {
        root.querySelectorAll('.kanban-column').forEach(function (col) {
            var count = col.querySelectorAll('.kanban-card').length;
            var badge = col.querySelector('.kanban-column__count');
            if (badge) badge.textContent = String(count);
            var empty = col.querySelector('.kanban-empty');
            var body = col.querySelector('.kanban-column__body');
            if (body) {
                if (count === 0 && !empty) {
                    var p = document.createElement('p');
                    p.className = 'kanban-empty text-body-md text-on-surface-variant';
                    p.textContent = 'No orders';
                    body.appendChild(p);
                } else if (count > 0 && empty) {
                    empty.remove();
                }
            }
        });
    }

    /* Shipment modal handlers */
    document.querySelectorAll('[data-open-shipment]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            openShipmentModal(parseInt(btn.getAttribute('data-open-shipment'), 10));
        });
    });

    document.querySelectorAll('[data-close-shipment-modal]').forEach(function (el) {
        el.addEventListener('click', closeShipmentModal);
    });

    document.querySelectorAll('[data-close-timeline-modal]').forEach(function (el) {
        el.addEventListener('click', closeTimelineModal);
    });

    document.querySelectorAll('[data-copy-tracking]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var t = btn.getAttribute('data-copy-tracking') || '';
            if (navigator.clipboard) {
                navigator.clipboard.writeText(t).then(function () { btn.textContent = 'Copied!'; });
            }
        });
    });

    document.querySelectorAll('[data-shipment-timeline]').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            var orderId = parseInt(btn.getAttribute('data-shipment-timeline'), 10);
            var data = await loadTimeline(orderId);
            if (data.success) {
                renderTimeline(data.timeline);
                if (timelineModal) {
                    timelineModal.hidden = false;
                    timelineModal.setAttribute('aria-hidden', 'false');
                }
            }
        });
    });

    document.querySelectorAll('[data-refresh-shipment]').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            var id = parseInt(btn.getAttribute('data-refresh-shipment'), 10);
            btn.disabled = true;
            try {
                var res = await fetch('/api/shipment.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ csrf_token: csrf, action: 'refresh', shipment_id: id })
                });
                var data = await res.json();
                if (data.success) {
                    location.reload();
                } else {
                    alert(data.error || 'Refresh failed');
                }
            } finally {
                btn.disabled = false;
            }
        });
    });

    if (shipmentForm) {
        shipmentForm.querySelectorAll('[name="courier_name"], [name="tracking_number"]').forEach(function (el) {
            el.addEventListener('blur', maybeAutoTrackingUrl);
        });

        shipmentForm.addEventListener('submit', async function (e) {
            e.preventDefault();
            var btn = shipmentForm.querySelector('[type="submit"]');
            if (btn) btn.disabled = true;
            try {
                var data = await createShipment(new FormData(shipmentForm));
                if (!data.success) {
                    alert(data.error || 'Could not save shipment');
                    return;
                }
                closeShipmentModal();
                location.reload();
            } finally {
                if (btn) btn.disabled = false;
            }
        });
    }

    /* Drag and drop */
    function onDragStart(e) {
        var card = cardFromEl(e.target);
        if (!card || card.classList.contains('kanban-card--busy')) {
            e.preventDefault();
            return;
        }
        dragging = card;
        card.classList.add('kanban-card--dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', card.getAttribute('data-order-id') || '');
    }

    function onDragEnd() {
        if (dragging) dragging.classList.remove('kanban-card--dragging');
        root.querySelectorAll('.kanban-column__body--over').forEach(function (b) {
            b.classList.remove('kanban-column__body--over');
        });
        dragging = null;
    }

    function onDragOver(e) {
        var body = columnBody(e.target);
        if (!body || !dragging) return;
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        body.classList.add('kanban-column__body--over');
    }

    function onDragLeave(e) {
        var body = columnBody(e.target);
        if (body && !body.contains(e.relatedTarget)) {
            body.classList.remove('kanban-column__body--over');
        }
    }

    async function onDrop(e) {
        e.preventDefault();
        var body = columnBody(e.target);
        if (!body || !dragging) return;
        body.classList.remove('kanban-column__body--over');

        var newStatus = statusFromColumn(body);
        var orderId = parseInt(dragging.getAttribute('data-order-id'), 10);
        var oldStatus = dragging.getAttribute('data-status');

        if (!newStatus || !orderId || newStatus === oldStatus) return;

        if (newStatus === 'shipped' && oldStatus !== 'shipped' && requiresShipment) {
            body.appendChild(dragging);
            var result = await moveOrder(orderId, newStatus, dragging);
            if (result === 'shipment_required') {
                var oldBody = root.querySelector('.kanban-column[data-status="' + oldStatus + '"] .kanban-column__body');
                if (oldBody) oldBody.appendChild(dragging);
            } else if (result === true) {
                updateColumnCounts();
                location.reload();
            } else {
                var ob = root.querySelector('.kanban-column[data-status="' + oldStatus + '"] .kanban-column__body');
                if (ob) ob.appendChild(dragging);
            }
            return;
        }

        body.appendChild(dragging);
        var ok = await moveOrder(orderId, newStatus, dragging);
        if (ok !== true) {
            var oldBody2 = root.querySelector('.kanban-column[data-status="' + oldStatus + '"] .kanban-column__body');
            if (oldBody2) oldBody2.appendChild(dragging);
        } else {
            updateColumnCounts();
            if (newStatus !== oldStatus) location.reload();
        }
    }

    root.querySelectorAll('.kanban-card').forEach(function (card) {
        card.setAttribute('draggable', 'true');
        card.addEventListener('dragstart', onDragStart);
        card.addEventListener('dragend', onDragEnd);
    });

    root.querySelectorAll('.kanban-column__body').forEach(function (body) {
        body.addEventListener('dragover', onDragOver);
        body.addEventListener('dragleave', onDragLeave);
        body.addEventListener('drop', onDrop);
    });
})();
