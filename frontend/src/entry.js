// Script entry: start the plugin, never throw into the page.
import { boot } from './index.js';

try {
  boot(window, document);
} catch (e) {
  /* the page must never notice a failure in monitoring code */
}
