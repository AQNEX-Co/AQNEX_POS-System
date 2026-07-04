<?php
namespace AQNEX\Models;

class Settings
{
    public int $id;
    public string $store_name;
    public ?string $phone;
    public ?string $address;
    public ?string $commercial_register;
    public ?string $tax_number;
    public string $currency;
    public int $barcode_scanner;
    public string $printer_type;
    public float $tax_percent;
    public int $low_stock_threshold;
    public ?string $receipt_footer;
    public ?string $logo;
    public ?string $cashier_permissions;
    public ?string $inventory_permissions;
    public int $is_configured;

    public function __construct(array $data = [])
    {
        $this->id = intval($data['id'] ?? 1);
        $this->store_name = (string)($data['store_name'] ?? '');
        $this->phone = $data['phone'] ?? null;
        $this->address = $data['address'] ?? null;
        $this->commercial_register = $data['commercial_register'] ?? null;
        $this->tax_number = $data['tax_number'] ?? null;
        $this->currency = (string)($data['currency'] ?? '');
        $this->barcode_scanner = intval($data['barcode_scanner'] ?? 1);
        $this->printer_type = (string)($data['printer_type'] ?? '');
        $this->tax_percent = floatval($data['tax_percent'] ?? 0);
        $this->low_stock_threshold = intval($data['low_stock_threshold'] ?? 5);
        $this->receipt_footer = $data['receipt_footer'] ?? null;
        $this->logo = $data['logo'] ?? null;
        $this->cashier_permissions = $data['cashier_permissions'] ?? null;
        $this->inventory_permissions = $data['inventory_permissions'] ?? null;
        $this->is_configured = intval($data['is_configured'] ?? 0);
    }
}
