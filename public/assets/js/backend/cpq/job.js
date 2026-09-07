/**
 * CPQ 异步任务监控（P105，GYTAI-78）
 * 任务列表（普通用户仅见自己发起）+ 详情 + 失败任务受控重试。
 */
define(['jquery', 'bootstrap', 'backend', 'table', 'form', 'backend/cpq/common'], function ($, undefined, Backend, Table, Form, CpqCommon) {
    'use strict';

    function escapeHtml(value) {
        return $('<span>').text(value == null ? '' : String(value)).html();
    }

    var statusText = {pending: '排队中', processing: '执行中', succeeded: '已成功', failed: '已失败', cancelled: '已取消'};
    var statusColor = {pending: 'default', processing: 'primary', succeeded: 'success', failed: 'danger', cancelled: 'warning'};

    function formatTime(value) {
        var seconds = parseInt(value, 10);
        return seconds ? new Date(seconds * 1000).toLocaleString() : '—';
    }

    var Controller = {
        index: function () {
            Table.api.init({extend: {index_url: 'cpq/job/index' + location.search}});
            var table = $('#table');
            var canSeeAll = !!Config.canSeeAll;
            var adminId = parseInt(Config.adminId, 10) || 0;

            function canRetry(row) {
                return row.status === 'failed' && (canSeeAll || parseInt(row.requested_by, 10) === adminId);
            }

            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                sortOrder: 'desc',
                search: false,
                commonSearch: false,
                queryParams: function (params) {
                    // 后端按 page/limit 分页：将 bootstrap-table 的 offset/limit 换算为 page
                    params.page = Math.floor((params.offset || 0) / (params.limit || 20)) + 1;
                    params.type = $('#cpq-job-type').val() || '';
                    params.status = $('#cpq-job-status').val() || '';
                    params.created_from = $.trim($('#cpq-job-from').val() || '');
                    params.created_to = $.trim($('#cpq-job-to').val() || '');
                    return params;
                },
                columns: [[
                    {field: 'id', title: 'ID', width: 60},
                    {field: 'job_key', title: '任务Key', formatter: function (value) { return '<code>' + escapeHtml(value) + '</code>'; }},
                    {field: 'type', title: '类型', formatter: function (value) { return escapeHtml(value); }},
                    {field: 'business_type', title: '业务对象', formatter: function (value, row) {
                        return escapeHtml((value || '—') + (row.business_id ? (' #' + row.business_id) : ''));
                    }},
                    {field: 'status', title: '状态', formatter: function (value) {
                        return '<span class="label label-' + (statusColor[value] || 'default') + '">' + escapeHtml(statusText[value] || value) + '</span>';
                    }},
                    {field: 'progress', title: '进度', width: 60, formatter: function (value) { return escapeHtml(value) + '%'; }},
                    {field: 'row_count', title: '行数', width: 70},
                    {
                        field: 'error_message', title: '错误摘要',
                        formatter: function (value, row) {
                            if (row.status !== 'failed' && row.status !== 'cancelled') {
                                return '—';
                            }
                            var code = row.error_code ? '[' + row.error_code + '] ' : '';
                            return '<span class="text-danger">' + escapeHtml(code + (value || '（无错误信息）')) + '</span>';
                        }
                    },
                    {field: 'requested_by', title: '发起人ID', width: 80},
                    {field: 'createtime', title: '创建时间', formatter: Table.api.formatter.datetime},
                    {
                        field: 'operate', title: __('Operate'), table: table,
                        events: {
                            'click .btn-cpq-detail': function (e, value, row) {
                                e.stopPropagation();
                                Controller.showDetail(row);
                            },
                            'click .btn-cpq-retry': function (e, value, row) {
                                e.stopPropagation();
                                Layer.confirm('确认重试任务 ' + escapeHtml(row.job_key) + '？将重新排队执行。', {icon: 3, title: '重试确认'}, function (index) {
                                    Layer.close(index);
                                    Fast.api.ajax({url: 'cpq/job/retry', type: 'POST', data: {job_key: row.job_key}}, function (data, ret) {
                                        Toastr.success(ret && ret.msg ? ret.msg : '已重新排队');
                                        table.bootstrapTable('refresh');
                                        return false;
                                    }, function (data, ret) {
                                        Layer.alert(escapeHtml(ret && ret.msg ? ret.msg : '重试失败'), {icon: 2, title: '重试被拒绝'});
                                        return false;
                                    });
                                });
                            }
                        },
                        buttons: [
                            {
                                name: 'detail', text: '详情', title: '任务详情', icon: 'fa fa-search',
                                classname: 'btn btn-xs btn-info btn-cpq-detail'
                            },
                            {
                                name: 'retry', text: '重试', title: '重试失败任务', icon: 'fa fa-repeat',
                                classname: 'btn btn-xs btn-warning btn-cpq-retry',
                                visible: canRetry
                            }
                        ],
                        formatter: Table.api.formatter.operate
                    }
                ]]
            });
            Table.api.bindevent(table);

            $('#cpq-job-type').on('change', function () {
                table.bootstrapTable('refresh');
            });
            $('#cpq-job-status').on('change', function () {
                table.bootstrapTable('refresh');
            });
            $('#cpq-job-from').on('changeDate', function () {
                table.bootstrapTable('refresh');
            });
            $('#cpq-job-to').on('changeDate', function () {
                table.bootstrapTable('refresh');
            });
        },
        showDetail: function (row) {
            Fast.api.ajax({url: 'cpq/job/detail', type: 'GET', data: {job_key: row.job_key}}, function (data, ret) {
                var job = (ret && ret.data && ret.data.job) || row;
                var rows = [
                    ['任务Key', '<code>' + escapeHtml(job.job_key) + '</code>'],
                    ['类型', escapeHtml(job.type)],
                    ['业务对象', escapeHtml((job.business_type || '—') + (job.business_id ? (' #' + job.business_id) : ''))],
                    ['状态', '<span class="label label-' + (statusColor[job.status] || 'default') + '">' + escapeHtml(statusText[job.status] || job.status) + '</span>'],
                    ['进度', escapeHtml(job.progress) + '%'],
                    ['行数', escapeHtml(job.row_count)],
                    ['发起人ID', escapeHtml(job.requested_by)],
                    ['重试次数', escapeHtml(job.retry_count + ' / ' + job.max_retries)],
                    ['创建时间', formatTime(job.createtime)],
                    ['开始时间', formatTime(job.started_at)],
                    ['完成时间', formatTime(job.finished_at)]
                ];
                var html = '<table class="table table-condensed table-bordered">';
                $.each(rows, function (_, item) {
                    html += '<tr><th style="width:110px">' + item[0] + '</th><td style="word-break:break-all">' + item[1] + '</td></tr>';
                });
                if (job.status === 'failed' || job.error_code || job.error_message) {
                    html += '<tr><th>错误</th><td class="text-danger" style="word-break:break-all">' +
                        escapeHtml('[' + (job.error_code || 'UNKNOWN') + '] ' + (job.error_message || '（无错误信息）')) + '</td></tr>';
                }
                html += '</table>';
                if (job.result) {
                    html += '<p class="text-muted" style="margin:10px 0 4px">结果摘要（不含下载令牌）：</p>' +
                        '<pre style="max-height:180px;overflow:auto;white-space:pre-wrap;word-break:break-all">' +
                        escapeHtml(JSON.stringify(job.result, null, 2)) + '</pre>';
                }
                if (job.has_error_report) {
                    html += '<p class="text-muted">该任务生成了错误报告，可经导出中心按下载令牌获取。</p>';
                }
                Layer.open({
                    type: 1,
                    title: '任务详情',
                    area: ['720px', '560px'],
                    content: '<div style="padding:15px;max-height:520px;overflow:auto">' + html + '</div>',
                    btn: ['关闭']
                });
                return false;
            }, function (data, ret) {
                Layer.alert(escapeHtml(ret && ret.msg ? ret.msg : '详情加载失败'), {icon: 2, title: '加载失败'});
                return false;
            });
        }
    };
    return Controller;
});
