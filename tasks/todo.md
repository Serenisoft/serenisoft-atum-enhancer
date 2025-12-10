# Task: Add Debug Logging for PO Generation Analysis

## Overview
Add detailed debug logging during PO generation to show the analysis process for each product. This will help users understand what the algorithm is doing and why certain products are selected for reordering.

## Requirements
- Log supplier name before processing each supplier's products
- Log one line per product in Pass 1 (basic reorder analysis)
- Log one line per product in Pass 2 (predictive analysis, if enabled)
- Show key metrics: current stock, reorder point, optimal stock, suggested qty
- Show the reason for reordering (at_rop, safety_margin, predictive)

## Implementation Plan

### Step 1: Add Setting to Enable Debug Logging
**File**: `classes/Settings/Settings.php`
**Location**: In `add_settings_defaults()` method

Add new setting:
```php
$defaults['sae_enable_debug_logging'] = array(
    'group'   => self::TAB_KEY,
    'section' => 'sae_po_suggestions',
    'name'    => __( 'Enable Debug Logging', 'serenisoft-atum-enhancer' ),
    'desc'    => __( 'Log detailed analysis for each product during PO generation. Check WordPress debug.log for output.', 'serenisoft-atum-enhancer' ),
    'type'    => 'switcher',
    'default' => 'no',
);
```

### Step 2: Add Supplier Logging in Generator
**File**: `classes/PurchaseOrderSuggestions/POSuggestionGenerator.php`
**Location**: At the start of each supplier processing loop (around line 230)

Add logging before calling algorithm:
```php
// Log supplier name if debug enabled
if ( 'yes' === Settings::get( 'sae_enable_debug_logging', 'no' ) ) {
    error_log( sprintf(
        'SAE DEBUG: === Processing Supplier #%d: %s ===',
        $supplier_id,
        $supplier_name
    ) );
}
```

### Step 3: Add Pass 1 Logging in Algorithm
**File**: `classes/PurchaseOrderSuggestions/POSuggestionAlgorithm.php`
**Location**: In `analyze_product()` method, after basic reorder check (line 133)

Add logging for Pass 1 with calculation details:
```php
// Debug logging for Pass 1 (basic reorder check with calculations)
if ( 'yes' === Settings::get( 'sae_enable_debug_logging', 'no' ) ) {
    $reason_text = $at_or_below_rop ? 'REORDER (at/below ROP)' : 'SKIP (above ROP)';

    // Log ROP calculation
    error_log( sprintf(
        'SAE DEBUG: [Pass 1] %s | ROP Calc: (%.2f avg/day × %d lead days) + %d safety = %d',
        $product->get_name(),
        $avg_daily_sales,
        $lead_time,
        $safety_stock,
        $reorder_point
    ) );

    // Log order quantity calculation
    error_log( sprintf(
        'SAE DEBUG: [Pass 1] %s | Order Calc: %d optimal - %d current - %d inbound = %d suggested',
        $product->get_name(),
        $optimal_stock,
        $current_stock,
        $inbound_stock,
        $suggested_qty
    ) );

    // Log decision
    error_log( sprintf(
        'SAE DEBUG: [Pass 1] %s | Decision: Stock %d vs ROP %d → %s',
        $product->get_name(),
        $effective_stock,
        $reorder_point,
        $reason_text
    ) );
}
```

### Step 4: Add Pass 2 Logging in Algorithm
**File**: `classes/PurchaseOrderSuggestions/POSuggestionAlgorithm.php`
**Location**: In `analyze_product()` method, after predictive checks (line 158)

Add logging for Pass 2 (only if predictive enabled) and separator:
```php
// Debug logging for Pass 2 (predictive analysis)
if ( $use_predictive && 'yes' === Settings::get( 'sae_enable_debug_logging', 'no' ) ) {
    $reasons = array();
    if ( $within_safety_margin ) $reasons[] = 'within safety margin';
    if ( $will_reach_rop_soon ) $reasons[] = 'will reach ROP soon';

    $reason_text = ! empty( $reasons )
        ? 'REORDER (' . implode( ', ', $reasons ) . ')'
        : 'SKIP (predictive not triggered)';

    error_log( sprintf(
        'SAE DEBUG: [Pass 2] %s | Stock: %d | Safety Threshold: %d | Days to ROP: %.1f | Suggested Qty: %d | %s',
        $product->get_name(),
        $effective_stock,
        (int) $safety_margin_threshold,
        $days_until_rop,
        $suggested_qty,
        $reason_text
    ) );
}

// Add separator line after each product for readability
if ( 'yes' === Settings::get( 'sae_enable_debug_logging', 'no' ) ) {
    error_log( 'SAE DEBUG: ---' );
}
```

### Step 5: Add Summary Logging
**File**: `classes/PurchaseOrderSuggestions/POSuggestionGenerator.php`
**Location**: After processing all products for a supplier

Add summary log:
```php
if ( 'yes' === Settings::get( 'sae_enable_debug_logging', 'no' ) ) {
    error_log( sprintf(
        'SAE DEBUG: Supplier #%d: %d products need reordering',
        $supplier_id,
        count( $products_to_reorder )
    ) );
}
```

## Example Output

```
SAE DEBUG: === Processing Supplier #34866: Example Supplier ===

SAE DEBUG: [Pass 1] [BRA-35] Product Name | ROP Calc: (2.00 avg/day × 14 lead days) + 13 safety = 41
SAE DEBUG: [Pass 1] [BRA-35] Product Name | Order Calc: 204 optimal - 50 current - 0 inbound = 154 suggested
SAE DEBUG: [Pass 1] [BRA-35] Product Name | Decision: Stock 50 vs ROP 41 → SKIP (above ROP)
SAE DEBUG: [Pass 2] [BRA-35] Product Name | Stock: 50 | Safety Threshold: 95 | Days to ROP: -2.1 | Suggested Qty: 154 | REORDER (within safety margin, will reach ROP soon)
SAE DEBUG: ---

SAE DEBUG: [Pass 1] [XYZ-123] Product Name | ROP Calc: (5.00 avg/day × 14 lead days) + 20 safety = 90
SAE DEBUG: [Pass 1] [XYZ-123] Product Name | Order Calc: 458 optimal - 200 current - 0 inbound = 258 suggested
SAE DEBUG: [Pass 1] [XYZ-123] Product Name | Decision: Stock 200 vs ROP 90 → SKIP (above ROP)
SAE DEBUG: [Pass 2] [XYZ-123] Product Name | Stock: 200 | Safety Threshold: 104 | Days to ROP: 22.0 | Suggested Qty: 0 | SKIP (predictive not triggered)
SAE DEBUG: ---

SAE DEBUG: Supplier #34866: 1 products need reordering
```

## Files to Modify

1. **`classes/Settings/Settings.php`** (~10 lines)
   - Add debug logging setting

2. **`classes/PurchaseOrderSuggestions/POSuggestionGenerator.php`** (~10 lines)
   - Add supplier name logging
   - Add summary logging

3. **`classes/PurchaseOrderSuggestions/POSuggestionAlgorithm.php`** (~30 lines)
   - Add Pass 1 logging
   - Add Pass 2 logging

**Total**: ~50 lines of new code

## Testing Plan

- [ ] Enable debug logging setting
- [ ] Run PO generation
- [ ] Check debug.log for supplier names
- [ ] Verify Pass 1 logging shows for all products
- [ ] Verify Pass 2 logging shows only when predictive is enabled
- [ ] Verify summary shows correct counts
- [ ] Disable debug logging and verify no logs appear

## Version

This will be version 0.7.0

## Todo List

- [ ] Add debug logging setting in Settings.php
- [ ] Add supplier logging in POSuggestionGenerator.php
- [ ] Add Pass 1 logging in POSuggestionAlgorithm.php
- [ ] Add Pass 2 logging in POSuggestionAlgorithm.php
- [ ] Add summary logging in POSuggestionGenerator.php
- [ ] Test logging output
- [ ] Update version to 0.7.0
- [ ] Update CHANGELOG.md
- [ ] Commit and push
