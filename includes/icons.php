<?php
if (!function_exists('get_icon')) {
    function get_icon($name, $classes = '') {
        $icon_map = [
            'home' => 'house',
            'sales' => 'cart3',
            'purchases' => 'truck',
            'expenses' => 'cash-coin',
            'receipts' => 'receipt',
            'box' => 'safe',
            'categories' => 'tags',
            'products' => 'box-seam',
            'inventory' => 'boxes',
            'customers' => 'people',
            'suppliers' => 'briefcase',
            'reports' => 'bar-chart-line',
            'users' => 'people-fill',
            'settings' => 'gear',
            'bank' => 'bank',
            'journal' => 'journal-text',
            'bolt' => 'lightning-charge',
            'changeuser' => 'person-bounding-box',
            'logout' => 'box-arrow-right',
            'plus' => 'plus-circle',
            'list' => 'list',
            'return' => 'arrow-right',
            'edit' => 'pencil-square',
            'trash' => 'trash',
            'search' => 'search',
            'print' => 'printer',
            'eye' => 'eye',
            'check' => 'check-circle',
            'save' => 'check-circle',
            'import' => 'upload',
            'whatsapp' => 'whatsapp',
            'info-circle' => 'info-circle'
        ];
        
        $bi_name = isset($icon_map[$name]) ? $icon_map[$name] : $name;
        $class_str = 'bi bi-' . $bi_name . ($classes ? ' ' . $classes : '');
        return '<i class="' . $class_str . '"></i>';
    }
}
?>