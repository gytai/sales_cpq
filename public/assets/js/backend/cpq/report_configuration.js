/**
 * P93 配置分析报表（GYTAI-78）。
 *
 * top 型号、选项频次（服务端有界采样统计）与校验失败统计；
 * 计数为整数原样展示，无金额换算。
 */
define(['jquery', 'bootstrap', 'backend', 'table', 'form', 'echarts', 'echarts-theme', 'backend/cpq/report_common'],
    function ($, _, Backend, Table, Form, echarts, __, ReportCommon) {
        'use strict';

        var Controller = {
            index: function () {
                var barChart = null;

                function renderKpis(payload) {
                    var row = $('#cpq-config-kpi').empty();
                    row.append(ReportCommon.renderKpi({label: '配置快照总数', value: String(payload.validated_total == null ? 0 : payload.validated_total)}));
                    row.append(ReportCommon.renderKpi({label: '校验失败数', value: String(payload.invalid_count == null ? 0 : payload.invalid_count)}));
                }

                function renderTopModels(rows) {
                    var body = $('#cpq-topmodel-body').empty();
                    $.each(rows || [], function (_, row) {
                        body.append(
                            '<tr>' +
                            '<td>' + ReportCommon.escapeHtml(row.model_code) + '</td>' +
                            '<td>' + row.line_count + '</td>' +
                            '<td>' + row.quote_count + '</td>' +
                            '</tr>'
                        );
                    });
                    if (!body.children().length) {
                        body.append('<tr><td colspan="3" class="text-muted text-center">暂无数据</td></tr>');
                    }
                }

                function renderOptions(rows) {
                    var body = $('#cpq-option-body').empty();
                    $.each(rows || [], function (_, row) {
                        body.append(
                            '<tr>' +
                            '<td>' + ReportCommon.escapeHtml(row.key) + '</td>' +
                            '<td>' + ReportCommon.escapeHtml(row.value) + '</td>' +
                            '<td>' + row.count + '</td>' +
                            '</tr>'
                        );
                    });
                    if (!body.children().length) {
                        body.append('<tr><td colspan="3" class="text-muted text-center">暂无数据</td></tr>');
                    }
                }

                function renderChart(rows) {
                    var el = document.getElementById('cpq-chart-config');
                    if (!el) {
                        return;
                    }
                    if (barChart) {
                        barChart.dispose();
                    }
                    barChart = echarts.init(el, 'walden');
                    barChart.setOption($.extend({}, ReportCommon.chartDefaults, {
                        tooltip: {trigger: 'axis', formatter: ReportCommon.tooltipFormatter},
                        grid: {left: 10, right: 20, top: 30, bottom: 10, containLabel: true},
                        xAxis: {type: 'value', name: '配置行数', minInterval: 1},
                        yAxis: {type: 'category', data: $.map((rows || []).slice().reverse(), function (row) { return row.model_code; })},
                        series: [{type: 'bar', barMaxWidth: 18, data: $.map((rows || []).slice().reverse(), function (row) { return row.line_count; })}]
                    }));
                }

                function load(filters) {
                    Fast.api.ajax({
                        url: 'cpq/report_configuration/index',
                        type: 'GET',
                        data: ReportCommon.canonicalFilters(filters)
                    }, function (data, ret) {
                        var payload = (ret && ret.data) || {};
                        renderKpis(payload);
                        renderTopModels(payload.top_models);
                        renderOptions(payload.option_frequency);
                        renderChart(payload.top_models);
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
