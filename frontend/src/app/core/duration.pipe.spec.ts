import { DurationPipe } from './duration.pipe';

describe('DurationPipe', () => {
  const pipe = new DurationPipe();
  it('shows decimal hours as hours and minutes', () => {
    expect(pipe.transform(11.83)).toBe('11 h 50 min');
    expect(pipe.transform(0.5)).toBe('0 h 30 min');
    expect(pipe.transform(16)).toBe('16 h 00 min');
  });
  it('carries rounded minutes into the next hour', () => {
    expect(pipe.transform(1.999)).toBe('2 h 00 min');
    expect(pipe.transform(0)).toBe('0 h 00 min');
    expect(pipe.transform(null)).toBe('Non disponibile');
  });
});
