import { floatingPanelPosition } from './floating-panel';

describe('Floating panels', () => {
  let sidebar: HTMLElement;
  let header: HTMLElement;
  beforeEach(() => {
    sidebar = document.createElement('aside');
    sidebar.className = 'sidebar';
    header = document.createElement('header');
    header.className = 'topbar';
    document.body.append(sidebar, header);
    vi.spyOn(sidebar, 'getBoundingClientRect').mockReturnValue(new DOMRect(0, 0, 70, 740));
    vi.spyOn(header, 'getBoundingClientRect').mockReturnValue(new DOMRect(70, 0, 235, 65));
    vi.spyOn(document.documentElement, 'clientWidth', 'get').mockReturnValue(305);
    vi.stubGlobal('innerHeight', 740);
  });
  afterEach(() => {
    sidebar.remove();
    header.remove();
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
  });
  it('keeps a filter beside the sidebar on a narrow phone', () => {
    const panel = floatingPanelPosition(new DOMRect(60, 150, 20, 30), 240, 210);
    expect(panel.left).toBeGreaterThanOrEqual(78);
    expect(panel.left + panel.width).toBeLessThanOrEqual(297);
  });
  it('keeps row actions on screen when the anchor is beyond a scrolled table edge', () => {
    const panel = floatingPanelPosition(new DOMRect(1100, 650, 25, 30), 185, 225, true);
    expect(panel.left + panel.width).toBeLessThanOrEqual(297);
    expect(panel.top + 225).toBeLessThanOrEqual(732);
  });
  it('keeps a panel below the sticky header', () => {
    const panel = floatingPanelPosition(new DOMRect(100, -50, 20, 20), 180, 180);
    expect(panel.top).toBeGreaterThanOrEqual(73);
  });
  it('uses the recovered width when the mobile sidebar is hidden', () => {
    sidebar.style.visibility = 'hidden';
    const panel = floatingPanelPosition(new DOMRect(12, 150, 20, 30), 240, 210);
    expect(panel.width).toBe(240);
    expect(panel.left).toBe(12);
  });
});
