# SereniSoft ATUM Enhancer

Extends ATUM Inventory Management for WooCommerce with intelligent purchase order suggestions based on stock levels, lead times, and sales patterns.

## Features

### Automatic PO Suggestions
- Analyzes stock levels and sales history
- Calculates optimal reorder points using industry-standard formulas
- Creates draft Purchase Orders per supplier
- Email notifications when suggestions are generated

### Smart Inventory Algorithms
- **Safety Stock**: Prevents stockouts using statistical service levels (90%, 95%, 99%)
- **Trend Detection**: Adjusts for growing or declining sales patterns
- **Seasonal Analysis**: Considers monthly sales variations
- **Dynamic History**: Handles new products with limited sales data
- **Inbound Stock Awareness**: Accounts for quantities already on order in pending POs

### Supplier Import
- CSV import for bulk supplier creation
- **Preview before import**: See what will be imported/skipped before committing
- Norwegian column format support
- Duplicate detection by code or name

## Requirements

- WordPress 5.9+
- WooCommerce 5.0+
- ATUM Inventory Management for WooCommerce
- PHP 7.4+

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`
2. Run `composer install` in the plugin directory
3. Activate the plugin through WordPress admin
4. Configure settings in **ATUM Inventory > Settings > Enhancer**

## Configuration

### General Settings
- **Notification Email**: Where to send PO suggestion alerts
- **Enable Automatic Suggestions**: Toggle automatic generation

### Purchase Order Algorithm
- **Default Orders Per Year**: How often to order from each supplier (1-12)
- **Minimum Days Between Orders**: Prevents too frequent orders
- **Service Level**: Target in-stock percentage (affects safety stock)
- **Include Seasonal Analysis**: Adjust for monthly patterns

### Supplier Import
Upload CSV files with semicolon-separated values:
```
Leverandornummer;Navn;Organisasjonsnummer;Telefonnummer;E-postadresse;Postadresse;Postnr.;Sted;Land
```

## Algorithm Details

### Reorder Point Formula
```
Reorder Point = (Average Daily Sales x Lead Time) + Safety Stock
```

### Safety Stock Formula
```
Safety Stock = Z x Standard Deviation x sqrt(Lead Time)
```

Where Z is the z-score for desired service level:
- 90% = 1.28
- 95% = 1.65
- 99% = 2.33

### Trend Adjustment
Recent sales (last 30 days) are weighted 70%, historical average 30%.

## License

GPLv2 or later

## Author

[SereniSoft](https://serenisoft.no/)
