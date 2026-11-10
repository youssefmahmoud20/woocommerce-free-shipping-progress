/* global wcfspConfig, jQuery, wp */
(() => {
    'use strict';
    const config = wcfspConfig;
    let timer, inFlight = false, dirty = false, revision = 0, lastRows;
    const mounts = () => [...document.querySelectorAll('.wcfsp')];

    function element(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text) node.textContent = text;
        return node;
    }

    function render(root, rows) {
        const content = root.querySelector('.wcfsp__content');
        if (!content) return;
        const key = JSON.stringify(rows);
        if (root.dataset.snapshot === key) return;
        root.dataset.snapshot = key;
        const fragment = document.createDocumentFragment();
        rows.forEach(row => {
            const item = element('div', `wcfsp__package wcfsp__package--${row.state}`);
            item.append(element('strong', 'wcfsp__label', row.label));
            item.append(element('p', 'wcfsp__message', row.message));
            if (typeof row.percent === 'number') {
                const progress = element('progress', 'wcfsp__bar');
                progress.max = 100;
                progress.value = Math.max(0, Math.min(100, row.percent));
                progress.setAttribute('aria-label', `${row.label}: ${config.progressLabel}`);
                item.append(progress);
            }
            if (row.note) item.append(element('small', 'wcfsp__note', row.note));
            fragment.append(item);
        });
        content.replaceChildren(fragment);
    }

    async function refresh() {
        if (!mounts().length || document.hidden) return;
        if (inFlight) { dirty = true; return; }
        inFlight = true;
        dirty = false;
        const requestedRevision = revision;
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 12000);
        try {
            const response = await fetch(config.url, {
                method: 'POST', credentials: 'same-origin', cache: 'no-store',
                signal: controller.signal
            });
            const payload = await response.json();
            if (!response.ok || !payload.success || !Array.isArray(payload.data.rows)) throw new Error('refresh');
            if (requestedRevision === revision) {
                lastRows = payload.data.rows;
                mounts().forEach(root => render(root, lastRows));
            } else dirty = true;
        } catch (error) {
            // Clear stale success messaging on failure; polling/events retry automatically.
            if (requestedRevision === revision) {
                lastRows = [{ label: '', message: config.error, state: 'notice', percent: null }];
                mounts().forEach(root => render(root, lastRows));
            }
        } finally {
            clearTimeout(timeout);
            inFlight = false;
            if (dirty) schedule();
        }
    }

    function schedule() {
        revision += 1;
        if (inFlight) dirty = true;
        clearTimeout(timer);
        timer = setTimeout(refresh, 250);
    }

    function start() {
        jQuery(document.body).on(
            'added_to_cart removed_from_cart updated_wc_div updated_cart_totals updated_checkout applied_coupon removed_coupon wc_fragments_loaded wc_fragments_refreshed', schedule
        );
        ['wc-blocks_added_to_cart', 'wc-blocks_removed_from_cart', 'wc-blocks_store_sync_required', 'wcfsp:refresh'].forEach(name => {
            document.body.addEventListener(name, schedule);
        });
        // Cart data covers block quantity, coupon and destination updates beyond add/remove events.
        let signature;
        if (window.wp && wp.data) {
            wp.data.subscribe(() => {
                const store = wp.data.select('wc/store/cart');
                if (!store || !store.getCartData) return;
                const cart = store.getCartData();
                const next = JSON.stringify([cart.items, cart.totals, cart.coupons, cart.shippingAddress, cart.shippingRates]);
                if (signature !== next) { signature = next; schedule(); }
            });
        }
        // Mini-cart fragments and client rendering may insert mounts after page load.
        new MutationObserver(records => {
            const added = records.some(record => [...record.addedNodes].some(node =>
                node.nodeType === 1 && (node.matches('.wcfsp') || node.querySelector('.wcfsp'))
            ));
            if (added) {
                if (lastRows) mounts().forEach(root => render(root, lastRows));
                schedule();
            }
        }).observe(document.body, { childList: true, subtree: true });
        window.addEventListener('focus', schedule);
        document.addEventListener('visibilitychange', () => { if (!document.hidden) schedule(); });
        // Pick up admin threshold changes in an already-open tab; no requests without a mount.
        setInterval(() => { if (!document.hidden) schedule(); }, 30000);
        schedule();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
    else start();
})();
