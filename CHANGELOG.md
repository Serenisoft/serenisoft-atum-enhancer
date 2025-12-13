# Changelog

All notable changes to SereniSoft ATUM Enhancer will be documented in this file.

## [0.9.7] - 2025-12-13

### Added
- **Generate PO Button on Purchase Orders Page**: Added "Generate PO Suggestions" button next to "Add New" on ATUM's Purchase Orders list page
  - Same functionality as the button in Settings
  - Includes confirmation modal, PO choice dialog, and result notifications
  - Makes PO generation more accessible without navigating to Settings

### Technical Details
- New class `POListButton` in `classes/PurchaseOrders/`
- Hooks into `load-edit.php` for the `atum_purchase_order` post type
- Injects button via jQuery, reuses existing AJAX handlers

## [0.9.6] - 2025-12-13

### Fixed
- **Sales Data Columns**: "Sold (Period)" now shows average per period (Year ÷ orders_per_year) instead of actual sales in last N days
  - Uses supplier-specific `orders_per_year` when configured, otherwise falls back to global default
  - Example: Product with 119 yearly sales and 4 orders/year now shows 30 (119÷4) instead of 8

## [0.9.5] - 2025-12-13

### Added
- **Sales Data Columns in Stock Central**: Two new columns showing historical sales data
  - "Sold (Year)" - Total quantity sold in the last 365 days
  - "Sold (Period)" - Total quantity sold in the last ordering period (365 / orders_per_year days)
  - "Fetch Sales Data" button in Stock Central header to load data on demand
  - Uses WooCommerce's `wc_order_product_lookup` table for efficient sales queries
  - HPOS (High-Performance Order Storage) compatible
  - Only fetches data when button is clicked to avoid performance impact on page load

### Technical Details
- New class `SalesDataColumns` for Stock Central sales columns
- Hooks: `atum/stock_central_list/page_title_buttons`, `atum/stock_central_list/table_columns`
- AJAX endpoint: `sae_fetch_sales_data` with nonce verification
- Columns placed in "Stock" group after stock column

## [0.9.4] - 2025-12-13

### Fixed
- **MOQ Column in Stock Central**: Fixed script not loading due to incorrect screen ID check
  - Changed from `toplevel_page_atum-stock-central` to `atum-inventory_page_atum-stock-central`
  - MOQ input field now works correctly for inline editing and saving

### Changed
- Removed debug logging from MOQ save handler (PHP and JavaScript)

## [0.9.3] - 2025-12-13

### Added
- **Minimum Order Quantity (MOQ)**: New field to specify minimum order quantity per product
  - Field in product admin page (ATUM panel) for simple and variable products
  - Variation-level MOQ support for variable products
  - MOQ column in Stock Central with direct inline editing (not popover)
  - PO algorithm rounds up suggested quantity to MOQ when calculated qty is lower
  - Debug logging shows MOQ adjustments during PO generation
  - Meta key: `_sae_moq`, default value: 1

### Technical Details
- New class `StockCentralColumns` for Stock Central MOQ column
- Hooks: `atum/stock_central_list/column_group_members`, `atum/stock_central_list/table_columns`
- Uses `atum/product_data` filter to save MOQ via ATUM's standard save mechanism
- Direct input field in Stock Central allows fast bulk editing by clicking and typing

## [0.9.2] - 2025-12-13

### Fixed
- **Order Quantity Calculation**: Fixed critical bug where suggested order quantities were incorrect
  - Previous: Calculated `optimal_stock - current_stock` (assumed immediate delivery)
  - Fixed: Now calculates `optimal_stock - stock_at_arrival` (accounts for lead time consumption)
  - Example: Product with 62 stock, 0.43 daily sales, 92-day lead time
    - Old calculation: 58 - 62 = -4 → 1 unit (wrong!)
    - New calculation: 58 - 22.9 (stock at arrival) = 36 units (correct!)
  - Orders now properly cover the target period (e.g., 3 months for 4 orders/year)

### Improved
- **Debug Logging**: Order calculation now shows projected stock at arrival
  - Format: `Order Calc: X optimal - Y stock@arrival (Z consumed in Nd) = Q suggested`
  - Makes it easier to verify the algorithm is working correctly

## [0.9.1] - 2025-12-13

### Added
- **Closure Period Buffers**: Safety margins before and after supplier closures
  - Buffer Before Closure (default 14 days): Accounts for pre-holiday delivery delays when suppliers rush to clear orders
  - Buffer After Closure (default 14 days): Factory ramp-up time after reopening before normal production resumes
  - Example: Christmas closure 20-12 to 05-01 with 14-day buffers → effective closure 06-12 to 19-01
  - Configurable in ATUM Settings → Enhancer → Closed Periods section

### Improved
- **Debug Logging**: Enhanced closed periods logging now shows:
  - Buffer values being applied
  - All closed periods for each supplier with effective dates (including buffers)
  - Clear visibility into how closures affect ordering calculations

## [0.9.0] - 2025-12-13

### Added
- **Supplier Closed Periods**: New feature to handle supplier closures (holidays, vacations, factory maintenance)
  - Global closed period presets in ATUM Settings → Enhancer tab
  - Supplier-level assignment: select global presets or add custom periods
  - DD-MM date format (Norwegian format, e.g., "01-07" for July 1st)
  - Automatic year-crossing support (e.g., "20-12 to 05-01" handles Christmas period)
  - **Type A - Delivery Date Adjustment**: Automatically extends lead time if delivery would fall during closure
  - **Type B - Predictive Ordering**: Orders in advance to prevent stockouts during supplier closures
  - Helper class `ClosedPeriodsHelper` with date logic and closure detection
  - AJAX-based period management with automatic save on changes
  - Polling mechanism to detect React-rendered elements in ATUM Settings SPA

### Fixed
- AJAX 403 error when saving closed periods (nonce read fresh from DOM instead of cached at document.ready)
- React/SPA compatibility: JavaScript polls for element existence before initialization

### Technical Details
- Separate WordPress option `sae_global_closed_periods` bypasses ATUM's HTML field save limitation
- `ClosedPeriodsHelper::get_adjusted_lead_time()` for Type A delivery date adjustments
- `ClosedPeriodsHelper::check_closure_depletion()` for Type B predictive ordering logic
- Periods stored per supplier as post meta: `_sae_closed_periods` with presets and custom arrays
- Date normalization converts DD-MM to timestamps for current and next year
- jQuery event delegation for dynamic ATUM React rendering

## [0.8.0] - 2025-12-10

### Changed
- **Improved Seasonal Analysis**: Completely redesigned to look at the future coverage period instead of current month
  - Now calculates when the order will arrive (today + lead time)
  - Analyzes the period the order needs to cover (arrival date + days of stock target)
  - Weights historical sales data for each month in the coverage period
  - **Removed dampening** - uses raw seasonal factor to capture true seasonal variations
  - **Increased cap from 2.0x to 4.0x** - essential for products with extreme seasonal variations
  - Critical for products like 800 units Oct-Mar, near-zero Apr-Sep
  - Example: Ordering in August with 60-day lead time now correctly forecasts for October arrival (high season)
  - Prevents under-ordering for seasonal peaks and over-ordering for off-season periods

### Technical Details
- `apply_seasonal_adjustment()` now accepts `$lead_time` and `$days_of_stock_target` parameters
- Calculates coverage period: `arrival_date = today + lead_time`, `coverage = arrival_date to arrival_date + days_of_stock`
- Queries historical sales grouped by month, then weights each month by how many days it covers in the future period
- Seasonal factor = (weighted monthly sales / expected baseline) with 0.5x-4.0x clamping
- **No dampening** - historical data reflects actual sales patterns, no artificial conservative bias
- **Global cap increased from 2.5x to 10x** - allows extreme seasonal variations while providing safety net against data errors
- Global cap (0.4x-10x on total adjustments) protects against data quality issues without limiting legitimate seasonal patterns
- Debug logging shows coverage period, months covered, historical sales, and seasonal factor applied

## [0.7.0] - 2025-12-10

### Added
- **Debug Logging**: New setting to enable detailed analysis logging during PO generation
  - Enable via "Enable Debug Logging" switch in PO Suggestions settings
  - Logs supplier name before processing each supplier's products
  - Logs Pass 1 analysis: ROP calculation, order quantity calculation, and decision for each product
  - Logs Pass 2 analysis: Predictive triggers, safety threshold, days to ROP, and suggested quantity
  - Separator lines between products for easy reading
  - Summary shows total products needing reordering per supplier
  - All debug output goes to WordPress debug.log (when WP_DEBUG_LOG is enabled)

## [0.6.0] - 2025-12-10

### Added
- **Dry Run Mode**: New setting to preview PO generation without creating actual Purchase Orders
  - Enable via "Enable Dry Run Mode" switch in PO Suggestions settings
  - Shows detailed preview of what would be created (products, quantities, suppliers)
  - Skips email notifications when in dry run mode
  - Useful for testing algorithm logic without creating test data
  - Warning-styled UI clearly indicates when in dry run mode
  - Message changes from "created" to "would be created" in dry run mode

## [0.5.1] - 2025-12-10

### Fixed
- **Bulk Supplier Assignment**: Fixed "Bulk action not found" error by using WordPress hooks (`atum_listTable_applyBulkAction`) instead of jQuery event interception
- **Supplier Search**: Fixed empty search results by using correct nonce (`search-products`) and transforming ATUM's response format to Select2 format
- **User Experience**: Removed success alert dialog after supplier assignment - now silently reloads table

### Technical Details
- Replaced jQuery click event handler with `wp.hooks.addFilter()` to intercept bulk action before ATUM sends AJAX
- Added `searchNonce` with correct `search-products` nonce for ATUM's supplier search AJAX handler
- Transformed ATUM's response format `{"123": "Name"}` to Select2 format `{results: [{id: "123", text: "Name"}]}`
- Used `selectWoo` (WooCommerce's Select2) with proper ATUM classes and data attributes

## [0.5.0] - 2025-12-10

### Added
- **Bulk Supplier Assignment**: New bulk action in ATUM Stock Central to assign suppliers to multiple products at once
  - Modal dialog for supplier selection using ATUM's Select2 search
  - Reuses ATUM's `atum_json_search_suppliers` AJAX action (identical to individual assignment)
  - Handles both simple and variable products (assigns to all variations automatically)
  - Permission check: Only users with `edit_suppliers` capability can use
  - Error handling: Shows success count and error details for failed products
  - Uses ATUM's data patterns: `Helpers::get_atum_product()` → `set_supplier_id()` → `save_atum_data()`

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
