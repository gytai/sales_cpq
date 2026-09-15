<?php
/**
 * GYTAI-82 页面一致性静态契约测试。
 *
 * 固化「各页面相同字段的交互方式一致」这一约束，覆盖：
 *  1. 日期列（effective_date / expiry_date / updatetime）在支持区间筛选时，
 *     必须同时具备 operate='RANGE' + addclass='datetimerange' + 日期格式化，
 *     不得出现「能筛不能看」或「能看不能筛」的页面；
 *  2. status 列统一走 Config.statusList + 状态配色映射；
 *  3. 版本列统一 operate=false（版本不是用户可筛维度）；
 *  4. 同一字段在各页面的列标题唯一，不出现同义多label；
 *  5. 表单中同一字段使用同一控件：target_type/target_id 联动的可搜索下拉、
 *     sales_org_id 的 selectpage、版本化实体的 status 隐藏域。
 *
 * 用法（容器内）：
 *   docker exec sales_cpq-app-1 php /var/www/html/tests/cpq/frontend_consistency.php
 */

$root = dirname(__DIR__, 2);
$jsDir = $root . '/public/assets/js/backend/cpq';
$viewDir = $root . '/application/admin/view/cpq';
$assertions = 0;

function checkConsistency($condition, $message)
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $message);
    }
}

/**
 * 取主列表的列定义块：脚本中第一个 columns:[[ ... ]] 即主表列，
 * 详情页内嵌子表（如价格表详情下的价格条目）不参与列表一致性约束。
 */
function mainColumnsBlock($source)
{
    $pos = strpos($source, 'columns:');
    if ($pos === false) {
        return $source;
    }
    $open = strpos($source, '[[', $pos);
    if ($open === false) {
        return $source;
    }
    $depth = 0;
    $length = strlen($source);
    for ($i = $open; $i < $length; $i++) {
        if ($source[$i] === '[') {
            $depth++;
        } elseif ($source[$i] === ']') {
            $depth--;
            if ($depth === 0) {
                return substr($source, $open, $i - $open + 1);
            }
        }
    }
    return $source;
}

/**
 * 解析一个列表脚本里的列定义：兼容 {field: 'x', ...} 与压缩写法 {field:'x',...}。
 * 返回 [field => [定义片段, ...]]（同一字段可能多次出现）。
 */
function parseColumns($source)
{
    $columns = [];
    if (!preg_match_all("/\{field:\s*'([a-z_0-9]+)'\s*,(.*?)\}/s", $source, $matches, PREG_SET_ORDER)) {
        return $columns;
    }
    foreach ($matches as $match) {
        $columns[$match[1]][] = $match[2];
    }
    return $columns;
}

$listScripts = [];
foreach (glob($jsDir . '/*.js') as $file) {
    $name = basename($file);
    if (in_array($name, ['common.js', 'report_common.js', 'quote_wizard.js'], true)) {
        continue;
    }
    $listScripts[$name] = file_get_contents($file);
}

checkConsistency($listScripts !== [], '未找到任何 CPQ 列表脚本');

// 报价列表全部列由服务端定制筛选（Quote::index 读 search/status/product_line），
// 列级 operate 统一为 false，不参与区间筛选约定。
$filterExempt = ['quote.js'];

$dateFields = ['effective_date', 'expiry_date', 'updatetime'];

foreach ($listScripts as $name => $source) {
    $columns = parseColumns(mainColumnsBlock($source));
    foreach ($dateFields as $field) {
        foreach ($columns[$field] ?? [] as $definition) {
            $hasRange = strpos($definition, "'RANGE'") !== false;
            $hasDateClass = strpos($definition, 'datetimerange') !== false;
            $hasFormatter = strpos($definition, 'formatter') !== false;
            if (in_array($name, $filterExempt, true)) {
                continue;
            }
            checkConsistency(
                $hasRange && $hasDateClass,
                sprintf('%s 的 %s 列缺少区间筛选（operate=RANGE + datetimerange）', $name, $field)
            );
            checkConsistency(
                $hasFormatter,
                sprintf('%s 的 %s 列缺少日期格式化，会原样输出数据库时间串', $name, $field)
            );
        }
    }

    foreach ($columns['version'] ?? [] as $definition) {
        checkConsistency(
            strpos($definition, 'operate: false') !== false || strpos($definition, 'operate:false') !== false,
            sprintf('%s 的版本列未关闭筛选（operate=false）', $name)
        );
    }

    foreach ($columns['status'] ?? [] as $definition) {
        // 走服务端状态字典（Config.statusList）的列，必须同时绑定共享配色映射，
        // 不允许各页面另起一套颜色；页面自有状态域（如异步作业）不在此约束内。
        if (strpos($definition, 'Config.statusList') !== false) {
            checkConsistency(
                strpos($definition, 'statusCustom') !== false,
                sprintf('%s 的 status 列使用了 Config.statusList 但未绑定共享配色 statusCustom', $name)
            );
        }
    }
}

// 同一字段的列标题必须唯一，避免同义多label造成理解成本
$sharedFields = ['market_scope', 'product_line', 'default_currency', 'target_id', 'is_required', 'effective_date', 'expiry_date'];
$titles = [];
foreach ($listScripts as $name => $source) {
    if (!preg_match_all("/\{field:\s*'([a-z_0-9]+)'\s*,\s*title:\s*'([^']*)'/s", $source, $matches, PREG_SET_ORDER)) {
        continue;
    }
    foreach ($matches as $match) {
        if (in_array($match[1], $sharedFields, true)) {
            $titles[$match[1]][$match[2]][] = $name;
        }
    }
}
foreach ($sharedFields as $field) {
    $variants = $titles[$field] ?? [];
    checkConsistency(
        count($variants) <= 1,
        sprintf(
            '字段 %s 存在多个列标题：%s',
            $field,
            implode(' / ', array_map(
                function ($title, $pages) {
                    return $title . '(' . implode(',', $pages) . ')';
                },
                array_keys($variants),
                $variants
            ))
        )
    );
}

// 表单控件一致性
$forms = [];
foreach (glob($viewDir . '/*/form.html') as $file) {
    $forms[basename(dirname($file))] = file_get_contents($file);
}
checkConsistency($forms !== [], '未找到任何 CPQ 表单视图');

function formControl($html, $field)
{
    if (preg_match('/<(input|select|textarea)[^>]*name="row\[' . preg_quote($field, '/') . '\]"[^>]*>/', $html, $match)) {
        return $match[0];
    }
    return '';
}

// 定价对象：target_type/target_id 联动下拉，两处必须复用同一数据源与联动锚点
foreach (['price_entry', 'price_policy'] as $page) {
    $control = formControl($forms[$page], 'target_id');
    checkConsistency($control !== '', $page . ' 缺少 target_id 表单控件');
    checkConsistency(
        strpos($control, 'cpq/price_entry/selecttarget') !== false,
        $page . ' 的 target_id 未复用统一定价对象数据源，需手工输入主键'
    );
    checkConsistency(
        strpos($control, 'id="cpq-target-id"') !== false,
        $page . ' 的 target_id 缺少联动锚点 id=cpq-target-id'
    );
    checkConsistency(
        strpos($forms[$page], 'id="cpq-target-type"') !== false || strpos($forms[$page], "'id'=>'cpq-target-type'") !== false,
        $page . ' 的 target_type 缺少联动锚点 cpq-target-type'
    );
    $script = file_get_contents($jsDir . '/' . $page . '.js');
    checkConsistency(
        strpos($script, '#cpq-target-id') !== false && strpos($script, 'selectPage') !== false,
        $page . ' 的脚本未绑定定价对象联动下拉'
    );
    // 联动关系：类型变更需清空已选对象，避免跨类型残留错误主键
    checkConsistency(
        strpos($script, '#cpq-target-type') !== false && strpos($script, 'selectPageClear') !== false,
        $page . ' 的脚本未按对象类型联动清空定价对象'
    );
}

// 销售组织：客户与区域表单使用同一 selectpage 数据源
foreach (['customer', 'region'] as $page) {
    $control = formControl($forms[$page], 'sales_org_id');
    checkConsistency(
        strpos($control, 'selectpage') !== false && strpos($control, 'cpq/sales_org/index') !== false,
        $page . ' 的 sales_org_id 未使用销售组织 selectpage，需手工输入主键'
    );
}

// 版本化实体的 status 由版本流按钮驱动，表单中必须是隐藏域而非可编辑下拉
$versionedPages = ['bom_mapping', 'config_rule', 'config_template', 'price_book', 'price_policy', 'price_rule', 'product_model', 'product_series', 'region', 'tax_rule', 'exchange_rate', 'fee_rule'];
foreach ($versionedPages as $page) {
    $control = formControl($forms[$page], 'status');
    checkConsistency(
        strpos($control, 'type="hidden"') !== false,
        $page . ' 为版本化实体，status 应为隐藏域（由版本流按钮驱动）'
    );
}

echo sprintf("CPQ 页面一致性静态契约: PASS（%d assertions）\n", $assertions);
