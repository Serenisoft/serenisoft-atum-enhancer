# Changelog

All notable changes to SereniSoft ATUM Enhancer will be documented in this file.

## [0.4.0] - 2025-12-10

### Added
- **Predictive Ordering**: Two-pass system to consolidate purchase orders
  - New "Predictive Ordering" settings section with comprehensive documentation
  - Enable Predictive Ordering master switch (default: enabled)
  - Safety Margin (%): Include products within % above reorder point (default: 15%, max: 100%)
  - Time-Based Prediction: Include products reaching ROP within 2× supplier lead time (default: enabled)
  - Pass 1: Identifies suppliers with urgent products (at/below reorder point)
  - Pass 2: For suppliers with urgent products, re-runs analysis with predictive features
  - Diagnostic fields: reorder_reason, days_until_rop, safety_margin_threshold
- Helper method `get_reorder_reason()` to track why products need reordering

### Changed
- Safety Margin setting renamed from "Stock Threshold (%)" with updated description
- Default safety margin reduced from 25% to 15%
- Maximum safety margin increased from 50% to 100%
- Two-pass filtering prevents premature PO creation while consolidating orders when needed

## [0.3.8] - 2025-12-09

### Changed
- Removed temporary debug console logging from PO choice execution flow

## [0.3.7] - 2025-12-09

### Fixed
- Removed ATUM's `script-runner` and `tool-runner` classes that triggered ATUM's own JavaScript handlers
- Prevented double pop-ups (ATUM's unexpected error + our modal) when clicking Generate button
- Now using custom CSS to style buttons like ATUM's without triggering their event handlers

## [0.3.6] - 2025-12-09

### Changed
- Redesigned all buttons to match ATUM's tool-runner style (using `.btn` and `.tool-runner` classes)
- Changed all buttons to use event delegation pattern (`$(document).on()`) consistent with ATUM
- Updated HTML structure to use ATUM's `script-runner` wrapper for better integration

### Fixed
- Import buttons (Preview, Import, Cancel) now work with ATUM's dynamic DOM loading
- All buttons follow ATUM's architecture patterns for consistency and reliability

## [0.3.5] - 2025-12-09

### Fixed
- "Generate PO Suggestions Now" button now works in all browsers including Brave (implemented event delegation)
- ATUM adds buttons dynamically after jQuery ready() - now using `$(document).on()` instead of direct element selection
- This fix makes the button work regardless of when ATUM injects the HTML into the DOM

## [0.3.4] - 2025-12-09

### Fixed
- "Generate PO Suggestions Now" button now works in Brave Browser (replaced native confirm() with custom HTML modal)
- Custom modal dialog prevents browser blocking issues and provides better user experience

## [0.3.3] - 2025-12-09

### Added
- Configurable run frequency: Daily / Twice Weekly / Weekly / Monthly
- Day selection for weekly and monthly schedules
- Custom WordPress cron schedules for new frequencies

### Changed
- Weekly frequency is now the default (recommended for most stores to reduce server load)
- Improved cron scheduling logic to calculate correct next run times based on frequency and day

## [0.3.2] - 2025-12-09

### Fixed
- "Enable Automatic Suggestions" checkbox now visible in ATUM settings (was hidden by ATUM's rendering logic)
- Added CSS override to force display of the checkbox field

## [0.3.1] - 2025-12-08

### Fixed
- Preview and Import buttons now work correctly in ATUM settings
- Moved JavaScript to `admin_print_footer_scripts` hook (WordPress sanitization strips inline scripts from HTML fields)

## [0.3.0] - 2025-12-08

### Added
- WP Cron integration for automatic daily PO generation
- "Scheduled Run Time" setting to choose when automatic generation runs (00:00-22:00)
- Automatic scheduling on plugin activation
- Re-scheduling when settings are changed
- Servebolt server-side cron compatibility

### Changed
- "Enable Automatic Suggestions" now triggers daily cron job instead of being inactive
- Improved settings descriptions for clarity

## [0.2.1] - 2025-12-08

### Added
- Per-supplier "Orders Per Year" override field on Supplier edit screen
- New "Enhancer Settings" meta box on ATUM Suppliers

## [0.2.0] - 2025-12-08

### Added
- CSV import preview - see data before importing with status per row
- Inbound stock awareness - algorithm accounts for quantities already on order

### Fixed
- Algorithm now subtracts pending PO quantities from suggested order amounts

## [0.1.0] - 2025-12-08

### Added
- Initial plugin structure with PSR-4 autoloading
- ATUM Settings integration with "Enhancer" tab
- Supplier CSV import with Norwegian column format
- Purchase Order suggestion algorithm
- Safety Stock calculation with configurable service levels (90%, 95%, 99%)
- Trend detection for growing/declining sales
- Seasonal sales adjustment
- Dynamic sales history for new products
- Email notifications for generated PO suggestions
- Manual "Generate PO Suggestions" button in settings
- HPOS (High-Performance Order Storage) compatibility

### Algorithm Features
- Reorder Point = (Avg Daily Sales x Lead Time) + Safety Stock
- Safety Stock = Z x σ(Demand) x √Lead Time
- Trend adjustment: 70% recent sales, 30% historical
- Handles products with limited sales history
- Accounts for inbound stock from pending purchase orders
