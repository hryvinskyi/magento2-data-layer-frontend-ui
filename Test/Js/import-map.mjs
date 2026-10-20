/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * Lets a test import a browser module whose own imports are bare specifiers. Import this first, then load the module
 * under test with a dynamic `import()`: static imports are all resolved before this one runs.
 */
import {register} from 'node:module';

register('./import-map-hooks.mjs', import.meta.url);
