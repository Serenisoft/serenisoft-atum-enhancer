# Changelog

All notable changes to SereniSoft ATUM Enhancer will be documented in this file.

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
