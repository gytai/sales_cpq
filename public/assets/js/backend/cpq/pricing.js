define(['jquery', 'bootstrap', 'backend', 'form', 'backend/cpq/common'], function ($, undefined, Backend, Form, CpqCommon) {
    'use strict';
    var lastSafeResult = null;
    function escape(value) { return $('<span>').text(value == null ? '' : String(value)).html(); }
    function jsonField(name, fallback) {
        var raw = $.trim($('[name="' + name + '"]').val() || '');
        if (!raw) { return fallback; }
        var parsed = JSON.parse(raw);
        if ((name === 'configuration' && (!parsed || Array.isArray(parsed) || typeof parsed !== 'object')) ||
            (name === 'accessories' && !Array.isArray(parsed))) {
            throw new Error(name === 'configuration' ? '配置 JSON 必须是对象' : '配件/服务 JSON 必须是数组');
        }
        return parsed;
    }
    function payload() {
        var data = {};
        $.each($('#cpq-pricing-form').serializeArray(), function (_, item) { data[item.name] = item.value; });
        data.model_id = String(data.model_id || '');
        data.customer_id = String(data.customer_id || '');
        data.quantity = String(data.quantity || '1');
        data.configuration = jsonField('configuration', {});
        data.accessories = jsonField('accessories', []);
        if (!data.manual_discount) { delete data.manual_discount; delete data.discount_reason; }
        if (!data.currency) { delete data.currency; }
        return data;
    }
    function amountRows(amounts) {
        var labels = {goods:'商品金额',goods_discounted:'折扣后商品金额',fees:'费用',untaxed:'未税金额',tax:'税额',total:'含税金额',converted_total:'换算后金额',control_unit_price:'控制价口径单价',cost_total:'成本合计'};
        var html = '<table class="table table-condensed table-bordered"><tbody>';
        $.each(amounts || {}, function (key, value) { html += '<tr><th>' + escape(labels[key] || key) + '</th><td>' + CpqCommon.moneyFormatter(value) + '</td></tr>'; });
        return html + '</tbody></table>';
    }
    function renderStep(step, index) {
        var title = (index + 1) + '. ' + (step.step || 'step');
        var detail = $.extend({}, step); delete detail.step;
        return '<div class="trace-step"><strong>' + escape(title) + '</strong><pre>' + escape(JSON.stringify(detail, null, 2)) + '</pre></div>';
    }
    function render(result) {
        lastSafeResult = result;
        $('#cpq-pricing-empty,#cpq-pricing-error').addClass('hidden');
        $('#cpq-pricing-result').removeClass('hidden');
        var total = result.totals && (result.totals.total || result.totals.converted_total);
        $('#cpq-total').text(total == null ? '—' : String(total));
        var approval = {none:'无需审批',line:'产线审批',company:'公司审批',forbidden:'禁止提交'};
        $('#cpq-approval').text(approval[result.approval_level] || result.approval_level || '—');
        $('#cpq-submittable').text(result.submittable ? '允许' : '禁止');
        var lines = '';
        $.each(result.lines || [], function (_, line) {
            lines += '<div class="panel panel-info"><div class="panel-heading">第 ' + escape(line.line_no) + ' 行 · 型号 ' + escape(line.model_id) + ' · ' + CpqCommon.classificationFormatter(line.classification) + '</div>' +
                '<div class="panel-body">' + amountRows(line.amounts) + '<p><strong>价格哈希：</strong><code>' + escape(line.price_hash || result.price_hash || '') + '</code></p></div></div>';
        });
        $('#cpq-lines').html(lines || '<p class="text-muted">无价格行。</p>');
        var trace = '';
        $.each(result.lines || [], function (_, line) {
            if (!line.price_trace) { return; }
            trace += '<h4>第 ' + escape(line.line_no) + ' 行执行轨迹</h4>';
            $.each(line.price_trace.steps || [], function (index, step) { trace += renderStep(step, index); });
            trace += '<h5>主数据快照</h5><pre>' + escape(JSON.stringify(line.price_trace.snapshots || {}, null, 2)) + '</pre>';
        });
        $('#cpq-trace').html(trace || '<p class="text-muted">本次为快速试算；点击“解释执行轨迹”获取规则命中顺序。</p>');
        $('#cpq-export').prop('disabled', !trace);
    }
    function request(action) {
        var body;
        try { body = payload(); } catch (error) { Toastr.error(error.message); return; }
        $('#cpq-pricing-error').addClass('hidden');
        Fast.api.ajax({url:'cpq/pricing/' + action,type:'POST',contentType:'application/json; charset=UTF-8',data:JSON.stringify(body)}, function (data) {
            render(data || {}); return false;
        }, function (data, ret) {
            lastSafeResult = null; $('#cpq-export').prop('disabled', true);
            var details = ret && ret.data && ret.data.details ? '\n' + JSON.stringify(ret.data.details, null, 2) : '';
            $('#cpq-pricing-result').addClass('hidden');
            $('#cpq-pricing-error').removeClass('hidden').text((ret && ret.msg ? ret.msg : '试算失败') + details);
            return false;
        });
    }
    var Controller = {index:function () {
        Form.api.bindevent($('#cpq-pricing-form'));
        $('#cpq-calculate').on('click', function () { request('calculate'); });
        $('#cpq-explain').on('click', function () { request('explain'); });
        $('#cpq-export').on('click', function () {
            if (!lastSafeResult) { return; }
            var blob = new Blob([JSON.stringify(lastSafeResult, null, 2)], {type:'application/json;charset=utf-8'});
            var url = URL.createObjectURL(blob), link = document.createElement('a');
            link.href = url; link.download = 'cpq-price-trace.json'; link.click(); URL.revokeObjectURL(url);
        });
    }};
    return Controller;
});
