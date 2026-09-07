/**
 * P91 折扣与毛利分析（GYTAI-78）。
 *
 * 产品线→型号聚合行；金额/折扣/毛利均为服务端 Decimal 字符串原样展示；
 * 毛利列按服务端返回键是否存在渲染（无权角色的行不含毛利键）。
 */
define(['jquery', 'bootstrap', 'backend', 'table', 'form', 'backend/cpq/report_common'],
    function ($, undefined, Backend, Table, Form, ReportCommon) {
        'use strict';

        var Controller = {
            index: function () {
                function renderTable(rows) {
                    var body = $('#cpq-pricing-body').empty();
                    var hasMargin = rows && rows.length && rows[0].gross_margin_amount !== undefined;
                    $('.cpq-col-margin-amount, .cpq-col-margin-rate').toggle(hasMargin);
                    $('#cpq-pricing-note').text(hasMargin
                        ? '毛利口径：未税金额 − 快照成本×数量（与导出一致），当前角色可见毛利列。'
                        : '当前角色无毛利查看权限，服务端未返回毛利字段。');
                    $.each(rows || [], function (_, row) {
                        body.append(
                            '<tr>' +
                            '<td>' + ReportCommon.escapeHtml(row.product_line) + '</td>' +
                            '<td>' + ReportCommon.escapeHtml(row.model_code) + '</td>' +
                            '<td>' + row.line_count + '</td>' +
                            '<td>' + ReportCommon.escapeHtml(row.avg_discount) + '</td>' +
                            '<td>' + ReportCommon.escapeHtml(row.untaxed_amount) + '</td>' +
                            '<td>' + ReportCommon.escapeHtml(row.total_amount) + '</td>' +
                            '<td>' + (hasMargin ? ReportCommon.escapeHtml(row.gross_margin_amount) : '') + '</td>' +
                            '<td>' + (hasMargin ? ReportCommon.escapeHtml(row.gross_margin_rate) : '') + '</td>' +
                            '</tr>'
                        );
                    });
                    if (!body.children().length) {
                        body.append('<tr><td colspan="8" class="text-muted text-center">当前筛选下暂无数据</td></tr>');
                    }
                }

                function load(filters) {
                    Fast.api.ajax({
                        url: 'cpq/report_pricing/index',
                        type: 'GET',
                        data: ReportCommon.canonicalFilters(filters)
                    }, function (data, ret) {
                        renderTable((ret && ret.data && ret.data.rows) || []);
                        return false;
                    }, function (data, ret) {
                        Layer.alert(ReportCommon.escapeHtml(ret && ret.msg ? ret.msg : '加载失败'), {icon: 2, title: '报表加载失败'});
                        return false;
                    });
                }

                ReportCommon.bindFilterForm('#cpq-filter-form', load);
                load({});
            }
        };

        return Controller;
    });
