// The recorder bundle: rrweb's record(), published as one global for the loader in index.js to pick up.
// Kept separate so visitors who are not recorded never download it.
import { record } from '@rrweb/record';

try {
  Object.defineProperty(window, '__ziploggerRecord', { value: record, configurable: true });
} catch (e) {
  window.__ziploggerRecord = record;
}
