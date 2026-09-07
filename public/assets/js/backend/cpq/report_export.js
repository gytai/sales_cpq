/**
 * P94 报价导出中心（GYTAI-78）。
 *
 * 任务列表（本人 excel_export 任务）+ 创建导出（统一筛选 + 幂等键，仅 POST）+
 * 有界轮询状态 + 一次性领取下载令牌 + 携带令牌下载 XLSX。
 */
define(['jquery', 'bootstrap', 'backend', 'table', 'form', 'backend/cpq/report_common'],
    function ($, undefined, Backend, Table, Form, ReportCommon) {
        'use strict';

        var STATUS_LABELS = {
            pending: '排队中', processing: '处理中',
            succeeded: '已完成', failed: '失败', cancelled: '已取消'
        };
        var POLL_INTERVAL = 2000;
        var POLL_MAX = 60;

        function newIdempotencyKey() {
            return 'exp-' + Date.now() + '-' + Math.random().toString(36).slice(2, 10);
        }

        var Controller = {
            index: function () {
                Table.api.init({
                    extend: {
                        index_url: 'cpq/report_export/index' + location.search
                    }
                });

                var table = $('#table');

                table.bootstrapTable({
                    url: $.fn.bootstrapTable.defaults.extend.index_url,
                    pk: 'job_key',
                    sortName: 'id',
                    sortOrder: 'desc',
                    search: false,
                    commonSearch: false,
                    columns: [
                        [
                            {field: 'job_key', title: '任务号', operate: false},
                            {field: 'status', title: '状态', operate: false, formatter: function (value) {
                                var cls = value === 'succeeded' ? 'label-success' : (value === 'failed' ? 'label-danger' : 'label-default');
                                return '<span class="label ' + cls + '">' + ReportCommon.escapeHtml(STATUS_LABELS[value] || value) + '</span>';
                            }},
                            {field: 'row_count', title: '行数', operate: false},
                            {field: 'progress', title: '进度', operate: false, formatter: function (value) {
                                return '<div class="progress progress-sm" style="margin:0;width:80px">' +
                                    '<div class="progress-bar" style="width:' + Math.min(100, Math.max(0, value)) + '%"></div></div>';
                            }},
                            {field: 'error_message', title: '错误信息', operate: false, formatter: function (value) {
                                return value ? '<span class="text-danger">' + ReportCommon.escapeHtml(value) + '</span>' : '-';
                            }},
                            {field: 'createtime', title: '创建时间', operate: false, formatter: Table.api.formatter.datetime},
                            {field: 'expires_at', title: '过期时间', operate: false, formatter: Table.api.formatter.datetime},
                            {
                                field: 'operate', title: __('Operate'), table: table,
                                buttons: [
                                    {
                                        name: 'download',
                                        text: '下载',
                                        title: '领取一次性下载令牌并下载 XLSX',
                                        classname: 'btn btn-xs btn-success btn-click',
                                        visible: function (row) {
                                            return row.status === 'succeeded';
                                        },
                                        click: function (row) {
                                            Controller.download(row.job_key);
                                        }
                                    }
                                ],
                                formatter: Table.api.formatter.operate
                            }
                        ]
                    ]
                });
                Table.api.bindevent(table);

                // 创建导出：提交筛选（含幂等键），成功后轮询直至终态
                $('#cpq-filter-form').on('submit', function (e) {
                    e.preventDefault();
                    Controller.create(ReportCommon.readFilters(this));
                    return false;
                });
                $('#cpq-filter-form .cpq-filter-reset').on('click', function () {
                    $('#cpq-filter-form [name]').each(function () {
                        $(this).val('');
                    });
                });
            },

            /**
             * 创建导出任务（仅 POST，白名单字段 + 幂等键）。
             */
            create: function (filters) {
                var data = $.extend({idempotency_key: newIdempotencyKey()}, filters);
                Fast.api.ajax({
                    url: 'cpq/report_export/create',
                    type: 'POST',
                    data: data
                }, function (data, ret) {
                    var jobKey = ret && ret.data && ret.data.job_key ? ret.data.job_key : '';
                    if (jobKey) {
                        Controller.poll(jobKey);
                    }
                    $('#table').bootstrapTable('refresh');
                    return false;
                }, function (data, ret) {
                    Layer.alert(ReportCommon.escapeHtml(ret && ret.msg ? ret.msg : '创建失败'), {icon: 2, title: '导出任务创建失败'});
                    return false;
                });
            },

            /**
             * 轮询任务状态直至终态（有界次数，避免悬挂定时器）。
             */
            poll: function (jobKey) {
                var attempts = 0;
                var timer = null;
                var stop = function () {
                    if (timer) {
                        clearTimeout(timer);
                        timer = null;
                    }
                    $('#table').bootstrapTable('refresh');
                };
                var tick = function () {
                    Fast.api.ajax({
                        url: 'cpq/report_export/status',
                        type: 'GET',
                        data: {job_key: jobKey}
                    }, function (data, ret) {
                        var job = (ret && ret.data) || {};
                        if (job.status === 'succeeded') {
                            Toastr.success('导出完成，可在列表中领取下载令牌');
                            stop();
                        } else if (job.status === 'failed' || job.status === 'cancelled') {
                            Layer.alert(ReportCommon.escapeHtml(job.error_message || '导出失败'), {icon: 2, title: '导出失败'});
                            stop();
                        } else if (++attempts >= POLL_MAX) {
                            Toastr.warning('导出仍在处理中，可稍后在列表中查看');
                            stop();
                        } else {
                            timer = setTimeout(tick, POLL_INTERVAL);
                        }
                        return false;
                    }, function () {
                        stop();
                        return false;
                    });
                };
                tick();
            },

            /**
             * 领取一次性下载令牌后跳转下载（令牌领取即销毁，不可重复）。
             */
            download: function (jobKey) {
                Fast.api.ajax({
                    url: 'cpq/report_export/claim',
                    type: 'POST',
                    data: {job_key: jobKey}
                }, function (data, ret) {
                    var token = ret && ret.data && ret.data.token ? ret.data.token : '';
                    if (!token) {
                        Layer.alert('下载令牌获取失败', {icon: 2, title: '下载失败'});
                        return false;
                    }
                    location.href = Fast.api.fixurl('cpq/report_export/download')
                        + '?job_key=' + encodeURIComponent(jobKey)
                        + '&token=' + encodeURIComponent(token);
                    $('#table').bootstrapTable('refresh');
                    return false;
                }, function (data, ret) {
                    Layer.alert(ReportCommon.escapeHtml(ret && ret.msg ? ret.msg : '令牌领取失败'), {icon: 2, title: '下载失败'});
                    return false;
                });
            }
        };

        return Controller;
    });
