# SereniSoft ATUM Enhancer

Extends ATUM Inventory Management for WooCommerce with intelligent purchase order suggestions based on stock levels, lead times, and sales patterns.

## Features

### Automatic PO Suggestions
- **Daily Automatic Generation**: Schedule PO suggestions to run automatically at a specific time
- Analyzes stock levels and sales history
- Calculates optimal reorder points using industry-standard formulas
- Creates draft Purchase Orders per supplier
- Email notifications when suggestions are generated
- Servebolt server-side cron compatible

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

### Supplier Closed Periods
- **Global Presets**: Define common closure periods (holidays, vacations) in ATUM Settings
- **Supplier Assignment**: Select global presets or add custom periods per supplier
- **DD-MM Format**: Norwegian date format (e.g., "01-07" for July 1st)
- **Year-Crossing Support**: Automatically handles periods like "20-12 to 05-01" (Christmas)
- **Type A - Delivery Adjustment**: Extends lead time if delivery falls during closure
- **Type B - Predictive Ordering**: Orders early to prevent stockouts during supplier closures
- Prevents ordering when you can't receive goods
- Ensures stock coverage through closure periods

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
- **Enable Automatic Suggestions**: Toggle automatic daily generation
- **Scheduled Run Time**: Choose time of day for automatic generation (00:00-22:00, server time)

### Purchase Order Algorithm
- **Default Orders Per Year**: How often to order from each supplier (1-12)
- **Minimum Days Between Orders**: Prevents too frequent orders
- **Service Level**: Target in-stock percentage (affects safety stock)
- **Include Seasonal Analysis**: Adjust for monthly patterns

### Per-Supplier Settings
Each supplier can override the global default:
- **Orders Per Year**: Found in "Enhancer Settings" meta box on Supplier edit screen

### Supplier Import
Upload CSV files with semicolon-separated values:
```
Leverandornummer;Navn;Organisasjonsnummer;Telefonnummer;E-postadresse;Postadresse;Postnr.;Sted;Land
```

## Algorithm Details

### Analysis Process Overview

The plugin uses a two-pass analysis system to determine which products need reordering:

#### **Pass 1 - Basic Reorder Analysis**

**Determines if ordering is needed:**
- Checks if effective stock ≤ reorder point (ROP)
- Formula: ROP = (Average Daily Sales × Lead Time) + Safety Stock
- Orders ONLY when inventory is at or below the reorder point

#### **Pass 2 - Predictive Analysis** (when enabled)

**Additional checks (in addition to Pass 1):**
1. **Safety Margin Check** - Are we within 15% above ROP?
2. **Time-Based Check** - Will we reach ROP within 2× lead time?

Pass 2 orders MORE PROACTIVELY - before you actually hit the reorder point.

### Calculation Process

1. **Calculate Average Daily Sales**
   - Adjusts for trend (last 30 days weighted 70%, history 30%)
   - Adjusts for seasonality using future-looking analysis:
     - Calculates when order arrives (today + lead time)
     - Determines coverage period (arrival + days of stock target)
     - Weights historical sales for each month in that future period
     - Critical for products with extreme seasonal variations

2. **Calculate Safety Stock**
   - Formula: Z-score × Standard Deviation × √Lead Time
   - Z-score = 1.65 for 95% service level

3. **Calculate Optimal Inventory Level**
   - Formula: (Average Daily Sales × Days Between Orders) + Safety Stock
   - Example: With 4 orders/year = 92 days of stock + safety stock

4. **Calculate Suggested Quantity**
   - Formula: Optimal Inventory - Effective Stock (including inbound stock)
   - Fills up to the optimal level

**In simple terms:** Pass 1 waits until you MUST order, Pass 2 orders BEFORE you run out.

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

### Seasonal Analysis (Future-Looking)

The seasonal adjustment uses a sophisticated future-looking approach instead of just looking at the current month:

**How it works:**
1. **Calculate arrival date**: Today + supplier lead time
2. **Calculate coverage period**: Arrival date + days between orders
3. **Analyze future months**: Determines which months the order will cover
4. **Weight historical data**: Each month's historical sales weighted by coverage days
5. **Adjust forecast**: Applies weighted seasonal factor to daily sales estimate

**Example scenario:**
- Today: August 15
- Lead time: 60 days → Arrives October 15
- Orders/year: 4 → Covers 92 days
- Coverage: Oct 15 - Jan 15 (15 days Oct, 30 days Nov, 31 days Dec, 16 days Jan)
- Product sells 800 units Oct-Mar, near-zero Apr-Sep
- Result: Correctly forecasts high seasonal demand and orders accordingly

**Seasonal factor:**
- No dampening applied - uses raw historical data
- Capped at 0.5x-4.0x to prevent extreme values
- Global safety net: Total adjustments (trend + seasonal) capped at 0.4x-10x
- 10x cap allows extreme seasonal variations while protecting against data errors

**Benefits:**
- Prevents under-ordering before seasonal peaks
- Prevents over-ordering for off-season periods
- Essential for products with extreme seasonal variations (e.g., 800 units in 6 months, near-zero in 6 months)
- Accounts for long lead times (2-3 months)
- Raw seasonal factor captures true demand patterns without artificial conservative bias

## License

GPLv2 or later

## Author

[SereniSoft](https://serenisoft.no/)
