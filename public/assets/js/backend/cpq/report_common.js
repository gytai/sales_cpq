/**
 * CPQ 报表共享模块（P01/P90-P94，GYTAI-78）。
 *
 * 统一筛选规范化、下钻链接构造与通用渲染工具：
 *  - canonicalFilters 仅保留白名单键并去除空值，与服务端
 *    ReportFilterService::ALLOWED_KEYS 对齐，保证驾驶舱/报表/导出/下钻
 *    使用同一套规范化筛选；
 *  - drilldownUrl 合并规范化筛选与区块增量筛选，生成报价列表下钻链接；
 *  - 金额一律渲染服务端 Decimal 字符串，本模块不做任何浮点运算。
 */
define(['jquery'], function ($) {
    'use strict';

    // 统一筛选白名单（company/sales_org_id/product_line/region_id/currency/created_from/created_to 为页面固定七字段）
    var FILTER_KEYS = ['company', 'sales_org_id', 'product_line', 'region_id', 'owner_id', 'currency', 'created_from', 'created_to', 'status'];

    function escapeHtml(value) {
        return $('<span>').text(value === null || value === undefined ? '' : String(value)).html();
    }

    // 规范化筛选：仅保留白名单键、去除空值，返回新对象（不修改入参）
    function canonicalFilters(input) {
        var out = {};
        if (!input) {
            return out;
        }
        $.each(FILTER_KEYS, function (_, key) {
            var value = input[key];
            if (value === undefined || value === null) {
                return;
            }
            value = $.trim(String(value));
            if (value === '') {
                return;
            }
            out[key] = value;
        });
        return out;
    }

    // 下钻链接：合并规范化筛选与区块增量筛选，携带全部筛选参数跳转；
    // base 经 Fast.api.fixurl 归一化为后台入口绝对路径，避免相对链接
    // 在 /cpq/dashboard/index 等深层页面下解析错位
    function drilldownUrl(base, filters, extra) {
        var merged = canonicalFilters(filters);
        $.each(extra || {}, function (key, value) {
            if (value === undefined || value === null || value === '') {
                return;
            }
            merged[key] = String(value);
        });
        var query = $.param(merged);
        var url = base + (query ? '?' + query : '');
        return window.Fast && Fast.api && Fast.api.fixurl ? Fast.api.fixurl(url) : url;
    }

    // 读取筛选表单（控件按 name 命名），返回规范化筛选
    function readFilters(form) {
        var raw = {};
        $(form).find('[name]').each(function () {
            raw[$(this).attr('name')] = $(this).val();
        });
        return canonicalFilters(raw);
    }

    // 绑定筛选表单：查询（提交）/重置均触发 onApply(filters)
    function bindFilterForm(form, onApply) {
        var $form = $(form);
        $form.on('submit', function (e) {
            e.preventDefault();
            onApply(readFilters($form));
            return false;
        });
        $form.find('.cpq-filter-reset').on('click', function () {
            $form.find('[name]').each(function () {
                $(this).val('');
            });
            onApply({});
        });
    }

    // KPI 卡片（金额/比率均为服务端字符串原样展示；drilldown 存在时整卡可点击）
    function renderKpi(options) {
        var card = $('<div class="col-sm-4 col-md-2">' +
            '<div class="panel panel-default cpq-kpi-card" style="margin-bottom:12px">' +
            '<div class="panel-body" style="padding:12px 15px">' +
            '<div class="text-muted" style="font-size:12px">' + escapeHtml(options.label) + '</div>' +
            '<div style="font-size:22px;font-weight:600;line-height:1.35;word-break:break-all">' + escapeHtml(options.value) + '</div>' +
            (options.sub ? '<div class="text-muted" style="font-size:12px">' + escapeHtml(options.sub) + '</div>' : '') +
            '</div></div></div>');
        if (options.drilldown) {
            card.find('.cpq-kpi-card').css('cursor', 'pointer');
        }
        return card;
    }

    // ECharts 统一默认值（walden 主题配色）
    var chartDefaults = {
        color: ['#18d1b1', '#3fb1e3', '#626c91', '#a0a7e6', '#c4ebad', '#96dee8'],
        grid: {left: 10, right: 20, top: 40, bottom: 10, containLabel: true}
    };

    // ECharts tooltip 统一安全格式化：系列名与值全部经 escapeHtml 转义，
    // 服务端返回的业务文本（报价名称等）不会被当作 HTML 注入执行。
    function tooltipFormatter(params) {
        var list = $.isArray(params) ? params : [params];
        var lines = [];
        $.each(list, function (_, p) {
            if (!p) { return; }
            var label = p.seriesName ? p.seriesName : (p.name != null ? p.name : (p.axisValue != null ? p.axisValue : ''));
            var value = $.isArray(p.value) ? p.value.join(', ') : (p.value != null ? p.value : '');
            lines.push((p.marker || '') + escapeHtml(String(label)) + '：' + escapeHtml(String(value)));
        });
        var title = list.length && list[0] && list[0].axisValue != null ? escapeHtml(String(list[0].axisValue)) + '<br>' : '';
        return title + lines.join('<br>');
    }

    return {
        FILTER_KEYS: FILTER_KEYS,
        canonicalFilters: canonicalFilters,
        drilldownUrl: drilldownUrl,
        readFilters: readFilters,
        bindFilterForm: bindFilterForm,
        escapeHtml: escapeHtml,
        renderKpi: renderKpi,
        chartDefaults: chartDefaults,
        tooltipFormatter: tooltipFormatter
    };
});
