# Changelog

All notable changes to SereniSoft ATUM Enhancer will be documented in this file.

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
