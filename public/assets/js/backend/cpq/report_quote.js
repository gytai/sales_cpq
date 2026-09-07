/**
 * P90 报价漏斗报表（GYTAI-78）。
 *
 * 六段漏斗 + 相邻转化率 + 段内 Top 报价；金额为服务端 Decimal 字符串，
 * 原样展示，段点击携带规范化筛选下钻报价列表。
 */
define(['jquery', 'bootstrap', 'backend', 'table', 'form', 'echarts', 'echarts-theme', 'backend/cpq/report_common'],
    function ($, _, Backend, Table, Form, echarts, __, ReportCommon) {
        'use strict';

        var STATUS_LABELS = {
            draft: '草稿', submitted: '待审批', approved: '已批准',
            sent: '已发送', accepted: '已接受', expired: '已过期'
        };

        var Controller = {
            index: function () {
                var currentFilters = {};
                var funnelChart = null;

                function drillTo(extra) {
                    location.href = ReportCommon.drilldownUrl('cpq/quote/index', currentFilters, extra || {});
                }

                function renderChart(segments) {
                    var el = document.getElementById('cpq-chart-funnel');
                    if (!el) {
                        return;
                    }
                    if (funnelChart) {
                        funnelChart.dispose();
                    }
                    funnelChart = echarts.init(el, 'walden');
                    funnelChart.setOption($.extend({}, ReportCommon.chartDefaults, {
                        tooltip: {
                            trigger: 'item',
                            formatter: function (info) {
                                var segment = segments[info.dataIndex] || {};
                                var amounts = segment.amounts || {};
                                var lines = '';
                                $.each(amounts, function (currency, amount) {
                                    lines += '<br>' + ReportCommon.escapeHtml(currency) + ' ' + ReportCommon.escapeHtml(amount);
                                });
                                return ReportCommon.escapeHtml(info.name) + '：' + info.value + ' 单' + lines;
                            }
                        },
                        series: [{
                            type: 'funnel',
                            left: '10%',
                            width: '80%',
                            top: 10,
                            bottom: 10,
                            minSize: '12%',
                            label: {show: true, position: 'inside'},
                            data: $.map(segments, function (segment) {
                                return {
                                    name: (STATUS_LABELS[segment.status] || segment.status) + '（' + segment.count + '）',
                                    value: segment.count
                                };
                            })
                        }]
                    }));
                    funnelChart.on('click', function (params) {
                        var segment = segments[params.dataIndex];
                        if (segment && segment.drilldown) {
                            drillTo(segment.drilldown);
                        }
                    });
                }

                function renderTable(segments) {
                    var body = $('#cpq-funnel-body').empty();
                    $.each(segments || [], function (_, segment) {
                        var amounts = segment.amounts || {};
                        var amountText = '';
                        $.each(amounts, function (currency, amount) {
                            if (amountText) {
                                amountText += '<br>';
                            }
                            amountText += ReportCommon.escapeHtml(currency) + ' ' + ReportCommon.escapeHtml(amount);
                        });
                        if (!amountText) {
                            amountText = '-';
                        }
                        var topText = $.map(segment.top_quotes || [], function (quote) {
                            return ReportCommon.escapeHtml(quote.code) + ' · ' + ReportCommon.escapeHtml(quote.customer_name)
                                + ' · ' + ReportCommon.escapeHtml(quote.currency + ' ' + quote.amount);
                        }).join('<br>') || '-';
                        var rate = segment.conversion_rate === '' ? '-' : ReportCommon.escapeHtml(segment.conversion_rate);
                        body.append(
                            '<tr style="cursor:pointer" data-status="' + ReportCommon.escapeHtml(segment.status) + '">' +
                            '<td>' + (STATUS_LABELS[segment.status] || ReportCommon.escapeHtml(segment.status)) + '</td>' +
                            '<td>' + segment.count + '</td>' +
                            '<td>' + amountText + '</td>' +
                            '<td>' + rate + '</td>' +
                            '<td style="white-space:normal">' + topText + '</td>' +
                            '</tr>'
                        );
                    });
                    body.find('tr').on('click', function () {
                        drillTo({status: $(this).data('status')});
                    });
                }

                function load(filters) {
                    currentFilters = ReportCommon.canonicalFilters(filters);
                    Fast.api.ajax({
                        url: 'cpq/report_quote/index',
                        type: 'GET',
                        data: currentFilters
                    }, function (data, ret) {
                        var segments = (ret && ret.data && ret.data.segments) || [];
                        renderChart(segments);
                        renderTable(segments);
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
