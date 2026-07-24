<?php
$dir_prefix = '../';
$module = 'products';
require_once($dir_prefix . 'includes/header.php');
check_permission(['admin', 'cashier']);

// جلب المنتجات للاستخدام المحلي
$products_list = [];
$res_prod = $conn->query("
    SELECT p.id, p.name, p.barcode, p.sale_price, p.has_multiple_units 
    FROM products p 
    WHERE p.delete_status = 0 
    ORDER BY p.id DESC
");
if ($res_prod) {
    while($row = $res_prod->fetch_assoc()) {
        $products_list[] = [
            'id' => intval($row['id']),
            'name' => $row['name'],
            'barcode' => $row['barcode'],
            'sale_price' => doubleval($row['sale_price']),
            'has_multiple_units' => intval($row['has_multiple_units'])
        ];
    }
}
$products_json = json_encode($products_list, JSON_UNESCAPED_UNICODE);

// جلب وحدات المنتجات
$units_map = [];
$res_units = $conn->query("SELECT id, product_id, unit_name, sale_price FROM product_units WHERE d_s = '0'");
if ($res_units) {
    while($row = $res_units->fetch_assoc()) {
        $units_map[$row['product_id']][] = [
            'id' => intval($row['id']),
            'unit_name' => $row['unit_name'],
            'sale_price' => doubleval($row['sale_price'])
        ];
    }
}
$units_json = json_encode($units_map, JSON_UNESCAPED_UNICODE);
?>

<title>طباعة ملصقات الباركود - تكنولوجيا فون</title>

<style>
.product-search-container {
    position: relative;
}
.autocomplete-dropdown {
    position: absolute;
    top: 100%;
    right: 0;
    width: 100%;
    background: #fff;
    border: 1px solid var(--secondary);
    border-top: none;
    max-height: 250px;
    overflow-y: auto;
    z-index: 1050;
    box-shadow: 0 4px 8px rgba(0,0,0,0.15);
}
.autocomplete-item {
    padding: 8px 12px;
    cursor: pointer;
    border-bottom: 1px solid #f1f5f9;
    font-size: 0.9rem;
    transition: background-color 0.2s;
    text-align: right;
}
.autocomplete-item:hover, .autocomplete-item.active {
    background-color: #f8f9fa;
    color: var(--secondary);
}
</style>

<div class="card-flat">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5><?php echo get_icon('archive', 'ml-2 text-primary'); ?> طباعة ملصقات الباركود والأسعار (ZPL)</h5>
        <a href="index.php" class="btn-flat btn-flat-secondary btn-sm text-decoration-none">
            <?php echo get_icon('logout', 'ml-1'); ?> عودة
        </a>
    </div>
    
    <div class="card-body">
        <div class="alert alert-info rounded-0 mb-4">
            <?php echo get_icon('info-circle', 'ml-2'); ?>
            قم بالبحث عن المنتجات وإضافتها إلى القائمة أدناه، ثم حدد عدد الملصقات المطلوبة والوحدة المناسبة لإرسال أمر الطباعة المباشر إلى طابعة الملصقات.
        </div>

        <!-- حقل البحث والبحث التلقائي -->
        <div class="row mb-4">
            <div class="col-md-8 mx-auto">
                <div class="product-search-container">
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text bg-primary text-white">
                                <?php echo get_icon('search'); ?>
                            </span>
                        </div>
                        <input type="text" id="productSearchInput" class="form-control form-control-lg rounded-0 border-primary" placeholder="ابحث باسم المنتج أو الباركود لإضافته..." autocomplete="off">
                    </div>
                    <div id="autocompleteDropdown" class="autocomplete-dropdown d-none"></div>
                </div>
            </div>
        </div>

        <!-- جدول ملصقات المنتجات المحددة -->
        <div class="table-responsive">
            <table class="table-flat border" id="labelsTable">
                <thead>
                    <tr>
                        <th style="width: 35%;">اسم المنتج</th>
                        <th style="width: 20%;">الباركود الحالي</th>
                        <th style="width: 20%;">الوحدة لطباعتها</th>
                        <th style="width: 15%;">عدد الملصقات</th>
                        <th style="width: 10%;">إجراء</th>
                    </tr>
                </thead>
                <tbody id="labelsContainer">
                    <tr id="emptyRow">
                        <td colspan="5" class="text-center text-muted py-4">ابحث عن المنتجات في الأعلى لإضافتها لقائمة الطباعة.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="text-left mt-4">
            <button type="button" id="btnPrintAll" class="btn-flat btn-flat-primary btn-lg px-5 d-none">
                <?php echo get_icon('print', 'ml-1'); ?> إرسال أوامر الطباعة المحددة
            </button>
        </div>
    </div>
</div>

<script>
const availableProducts = <?php echo $products_json; ?>;
const productUnitsMap = <?php echo $units_json; ?>;

document.addEventListener("DOMContentLoaded", function() {
    const searchInput = document.getElementById("productSearchInput");
    const dropdown = document.getElementById("autocompleteDropdown");
    const labelsContainer = document.getElementById("labelsContainer");
    const emptyRow = document.getElementById("emptyRow");
    const btnPrintAll = document.getElementById("btnPrintAll");
    
    let selectedProducts = [];

    // البحث في المنتجات المحلية
    searchInput.addEventListener("input", function() {
        const query = this.value.trim().toLowerCase();
        if (!query) {
            dropdown.classList.add("d-none");
            dropdown.innerHTML = "";
            return;
        }

        const matches = availableProducts.filter(p => 
            p.name.toLowerCase().includes(query) || 
            (p.barcode && p.barcode.toLowerCase().includes(query))
        ).slice(0, 10);

        if (matches.length === 0) {
            dropdown.innerHTML = '<div class="autocomplete-item text-center text-muted">لا يوجد منتج مطابق</div>';
            dropdown.classList.remove("d-none");
            return;
        }

        let html = "";
        matches.forEach((p, idx) => {
            html += `
                <div class="autocomplete-item" data-id="${p.id}" data-idx="${idx}">
                    <div class="font-weight-bold text-dark">${p.name}</div>
                    <div class="item-meta text-muted small">
                        <span>الباركود: ${p.barcode || '-'}</span> | 
                        <span>السعر: ${p.sale_price} ر.ي</span>
                    </div>
                </div>
            `;
        });
        dropdown.innerHTML = html;
        dropdown.classList.remove("d-none");
    });

    // اختيار منتج من القائمة
    dropdown.addEventListener("click", function(e) {
        const item = e.target.closest(".autocomplete-item");
        if (item) {
            const id = parseInt(item.getAttribute("data-id"));
            const product = availableProducts.find(p => p.id === id);
            if (product) {
                addProductToQueue(product);
            }
            searchInput.value = "";
            dropdown.classList.add("d-none");
            dropdown.innerHTML = "";
        }
    });

    // إغلاق قائمة البحث عند النقر خارجها
    document.addEventListener("click", function(e) {
        if (!e.target.closest(".product-search-container")) {
            dropdown.classList.add("d-none");
        }
    });

    function addProductToQueue(product) {
        // التحقق من تكرار المنتج
        const exists = selectedProducts.some(p => p.id === product.id);
        if (exists) {
            alert("المنتج مضاف بالفعل في قائمة الطباعة.");
            return;
        }

        selectedProducts.push(product);
        
        if (emptyRow) emptyRow.classList.add("d-none");
        btnPrintAll.classList.remove("d-none");

        // جلب وحدات هذا المنتج إن وجدت
        const units = productUnitsMap[product.id] || [];
        
        let unitOptions = `<option value="">حبة (${product.barcode || '-'})</option>`;
        units.forEach(u => {
            unitOptions += `<option value="${u.id}">${u.unit_name} (${u.sale_price} ر.ي)</option>`;
        });

        const tr = document.createElement("tr");
        tr.className = "label-row";
        tr.setAttribute("data-id", product.id);
        tr.innerHTML = `
            <td class="font-weight-bold text-dark">${product.name}</td>
            <td class="barcode-display">${product.barcode || '-'}</td>
            <td>
                <select class="form-control form-control-sm rounded-0 unit-select">
                    ${unitOptions}
                </select>
            </td>
            <td>
                <input type="number" class="form-control form-control-sm text-center rounded-0 qty-input" value="1" min="1" style="max-width: 100px; margin: 0 auto;">
            </td>
            <td>
                <button type="button" class="btn btn-danger btn-sm rounded-0 btn-remove-row">حذف</button>
            </td>
        `;

        // عند تغيير وحدة القياس، يتم تحديث الباركود الظاهر في الجدول
        tr.querySelector(".unit-select").addEventListener("change", function() {
            const unitId = this.value;
            if (unitId === "") {
                tr.querySelector(".barcode-display").textContent = product.barcode || '-';
            } else {
                // سنقوم بالاستعلام عن باركود الوحدة أو الاكتفاء بالأساسي
                fetch(`../sales/api_lookup_barcode.php?barcode=${product.barcode}`)
                    .then(r => r.json())
                    .then(d => {
                        // سنتركه يطبع بالوحدة التي يتم تمريرها
                    });
            }
        });

        labelsContainer.appendChild(tr);
    }

    // إزالة منتج من القائمة
    labelsContainer.addEventListener("click", function(e) {
        if (e.target.classList.contains("btn-remove-row")) {
            const tr = e.target.closest("tr");
            const id = parseInt(tr.getAttribute("data-id"));
            selectedProducts = selectedProducts.filter(p => p.id !== id);
            tr.remove();
            
            if (selectedProducts.length === 0) {
                if (emptyRow) emptyRow.classList.remove("d-none");
                btnPrintAll.classList.add("d-none");
            }
        }
    });

    // طباعة كل العناصر
    btnPrintAll.addEventListener("click", function() {
        const rows = labelsContainer.querySelectorAll(".label-row");
        if (rows.length === 0) return;

        let jobs = [];
        rows.forEach(row => {
            const productId = row.getAttribute("data-id");
            const unitId = row.querySelector(".unit-select").value;
            const qty = row.querySelector(".qty-input").value;
            
            let url = `../printing/api_print.php?action=print_label&product_id=${productId}&qty=${qty}`;
            if (unitId) {
                url += `&unit_id=${unitId}`;
            }
            
            jobs.push(
                fetch(url)
                    .then(r => r.json())
                    .then(data => {
                        return { name: row.cells[0].textContent, data: data };
                    })
                    .catch(e => {
                        return { name: row.cells[0].textContent, error: e };
                    })
            );
        });

        btnPrintAll.setAttribute("disabled", "disabled");
        btnPrintAll.textContent = "جاري إرسال أوامر الطباعة...";

        Promise.all(jobs).then(results => {
            let successMsgs = [];
            let errorMsgs = [];
            results.forEach(res => {
                if (res.data && res.data.success) {
                    successMsgs.push(`✓ تم إرسال ملصق "${res.name}" للطابعة.`);
                } else {
                    errorMsgs.push(`✗ فشل طباعة ملصق "${res.name}": ` + (res.data ? res.data.message : res.error));
                }
            });

            if (successMsgs.length > 0) alert(successMsgs.join("\n"));
            if (errorMsgs.length > 0) alert(errorMsgs.join("\n"));

            btnPrintAll.removeAttribute("disabled");
            btnPrintAll.innerHTML = `<?php echo get_icon('print', 'ml-1'); ?> إرسال أوامر الطباعة المحددة`;
        });
    });
});
</script>

<?php require_once($dir_prefix . 'includes/footer.php'); ?>
