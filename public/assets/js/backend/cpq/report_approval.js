/**
 * P92 审批效率报表（GYTAI-78）。
 *
 * 节点/审批人两维：任务数、平均耗时（服务端格式化字符串）、SLA 超时数、
 * 拒绝率（服务端 Decimal 字符串）；节点耗时以柱状图辅助对比。
 */
define(['jquery', 'bootstrap', 'backend', 'table', 'form', 'echarts', 'echarts-theme', 'backend/cpq/report_common'],
    function ($, _, Backend, Table, Form, echarts, __, ReportCommon) {
        'use strict';

        var Controller = {
            index: function () {
                var barChart = null;

                function renderRows(bodySelector, rows, nameFormatter) {
                    var body = $(bodySelector).empty();
                    $.each(rows || [], function (_, row) {
                        body.append(
                            '<tr>' +
                            '<td>' + nameFormatter(row) + '</td>' +
                            '<td>' + row.task_count + '</td>' +
                            '<td>' + ReportCommon.escapeHtml(row.avg_hours) + '</td>' +
                            '<td>' + (row.overdue_count > 0 ? '<span class="label label-danger">' + row.overdue_count + '</span>' : row.overdue_count) + '</td>' +
                            '<td>' + ReportCommon.escapeHtml(row.reject_rate) + '</td>' +
                            '</tr>'
                        );
                    });
                    if (!body.children().length) {
                        body.append('<tr><td colspan="5" class="text-muted text-center">暂无数据</td></tr>');
                    }
                }

                function renderChart(byNode) {
                    var el = document.getElementById('cpq-chart-approval');
                    if (!el) {
                        return;
                    }
                    if (barChart) {
                        barChart.dispose();
                    }
                    barChart = echarts.init(el, 'walden');
                    barChart.setOption($.extend({}, ReportCommon.chartDefaults, {
                        tooltip: {trigger: 'axis', formatter: ReportCommon.tooltipFormatter},
                        xAxis: {type: 'category', data: $.map(byNode || [], function (row) { return row.node; })},
                        yAxis: {type: 'value', name: '平均耗时（小时）'},
                        series: [{
                            type: 'bar',
                            barMaxWidth: 40,
                            data: $.map(byNode || [], function (row) { return row.avg_hours; })
                        }]
                    }));
                }

                function load(filters) {
                    Fast.api.ajax({
                        url: 'cpq/report_approval/index',
                        type: 'GET',
                        data: ReportCommon.canonicalFilters(filters)
                    }, function (data, ret) {
                        var payload = (ret && ret.data) || {};
                        renderRows('#cpq-node-body', payload.by_node, function (row) {
                            return ReportCommon.escapeHtml(row.node);
                        });
                        renderRows('#cpq-assignee-body', payload.by_assignee, function (row) {
                            return ReportCommon.escapeHtml(row.assignee_name || ('#' + row.assignee_id));
                        });
                        renderChart(payload.by_node);
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
