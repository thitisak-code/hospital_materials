document.addEventListener('DOMContentLoaded', () => {
    // Make wide tables keyboard-scrollable without changing their data or actions.
    document.querySelectorAll('.table-responsive').forEach(region => {
        region.tabIndex = 0;
        region.setAttribute('role', 'region');
        region.setAttribute('aria-label', 'ตารางข้อมูล เลื่อนซ้ายขวาเพื่อดูครบทุกคอลัมน์');
        const hint = document.createElement('div');
        hint.className = 'table-scroll-hint no-print';
        hint.textContent = 'เลื่อนตารางซ้าย–ขวาเพื่อดูข้อมูลเพิ่มเติม';
        region.before(hint);
        const update = () => { hint.hidden = region.scrollWidth <= region.clientWidth + 1; };
        new ResizeObserver(update).observe(region);
        region.querySelectorAll('table').forEach(table => new ResizeObserver(update).observe(table));
        update();
    });
    // Links to history must activate the Bootstrap tab, including links from other pages.
    const showLinkedTab = () => {
        const pane = document.getElementById(location.hash.slice(1));
        if (!pane?.classList.contains('tab-pane')) return;
        const trigger = Array.from(document.querySelectorAll('[data-bs-toggle="tab"]'))
            .find(button => button.getAttribute('data-bs-target') === location.hash);
        if (trigger && window.bootstrap) {
            bootstrap.Tab.getOrCreateInstance(trigger).show();
            pane.scrollIntoView({ block: 'start' });
        }
    };
    window.addEventListener('hashchange', showLinkedTab);
    showLinkedTab();
});
