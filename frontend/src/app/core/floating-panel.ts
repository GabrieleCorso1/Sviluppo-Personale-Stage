export function floatingPanelPosition(anchor: DOMRect, preferredWidth: number, preferredHeight: number, alignRight = false) {
  const sidebar = document.querySelector('.sidebar');
  const sidebarRight = sidebar && getComputedStyle(sidebar).visibility !== 'hidden' ? sidebar.getBoundingClientRect().right : 0;
  const minLeft = Math.max(8, sidebarRight + 8);
  const width = Math.min(preferredWidth, Math.max(0, document.documentElement.clientWidth - minLeft - 8));
  const maxLeft = document.documentElement.clientWidth - width - 8;
  const desiredLeft = alignRight ? anchor.right - width : anchor.left;
  const minTop = Math.max(8, (document.querySelector('.topbar')?.getBoundingClientRect().bottom ?? 0) + 8);
  return {
    left: Math.max(minLeft, Math.min(desiredLeft, maxLeft)),
    top: Math.max(minTop, Math.min(anchor.bottom + 8, window.innerHeight - preferredHeight - 8)),
    width,
  };
}
