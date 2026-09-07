define(['jquery', 'bootstrap', 'backend', 'table', 'form', 'backend/cpq/common'], function ($, undefined, Backend, Table, Form, CpqCommon) {

    // 发布弹窗：变更摘要 + 计划生效时间；维度重叠冲突等错误以弹窗完整展示服务端消息
    function publishButton(baseUrl, auth, titleLabel) {
        return {
            name: 'publish',
            text: '发布',
            title: '发布',
            icon: 'fa fa-check',
            classname: 'btn btn-xs btn-success',
            extend: 'data-toggle="tooltip" data-container="body"',
            visible: function (row) {
                return auth.publish && row.status === 'pending';
            },
            events: {
                'click': function (e, value, row) {
                    e.stopPropagation();
                    e.preventDefault();
                    Layer.open({
                        type: 1,
                        title: '发布' + titleLabel + '：' + $('<span>').text(row.code + '（版本 ' + row.version + '）').html(),
                        area: ['560px', '380px'],
                        content: '<div style="padding:20px">' +
                            '<div class="form-group"><label>变更摘要</label>' +
                            '<textarea id="cpq-publish-summary" class="form-control" rows="4" placeholder="本次发布变更内容说明（将登记到发布记录）"></textarea></div>' +
                            '<div class="form-group"><label>计划生效时间</label>' +
                            '<input id="cpq-publish-planned" class="form-control" placeholder="YYYY-MM-DD HH:mm:ss（可留空）"></div>' +
                            '<p class="text-muted">发布后不可直接修改；如与同维度已发布策略冲突，服务端将拒绝并展示冲突详情。</p></div>',
                        btn: ['发布', '取消'],
                        yes: function (index) {
                            Fast.api.ajax({
                                url: baseUrl + '/publish',
                                type: 'POST',
                                data: {
                                    ids: row.id,
                                    change_summary: $('#cpq-publish-summary').val(),
                                    planned_effective_at: $('#cpq-publish-planned').val()
                                }
                            }, function () {
                                Layer.close(index);
                                $('#table').trigger('uncheckbox');
                                $('#table').bootstrapTable('refresh');
                                return false;
                            }, function (data, ret) {
                                var message = (ret && ret.msg) ? String(ret.msg) : '发布失败';
                                Layer.alert(
                                    '<div style="max-height:320px;overflow:auto;word-break:break-all">' +
                                    $('<div>').text(message).html().replace(/\n/g, '<br>') + '</div>',
                                    {icon: 2, title: '发布失败'}
                                );
                                return false;
                            });
                        }
                    });
                    return false;
                }
            }
        };
    }

    var Controller = {
        index: function () {
            Table.api.init({extend: {
                index_url: 'cpq/price_policy/index' + location.search,
                add_url: 'cpq/price_policy/add',
                edit_url: 'cpq/price_policy/edit',
                del_url: 'cpq/price_policy/del',
                multi_url: 'cpq/price_policy/multi',
                detail_url: 'cpq/price_policy/detail',
                table: 'cpq_price_policy'
            }});
            var table = $('#table');
            var baseUrl = 'cpq/price_policy';
            var auth = CpqCommon.readAuth(table, ['submit', 'publish', 'expire', 'copy', 'importpreview']);
            // 敏感字段脱敏：按 Config.canViewCost / Config.canViewCompanyFloor 决定是否加入列；
            // 服务端已对行数据脱敏（无权限时行里无对应键），前端不补占位值。
            var columns = [
                {checkbox: true},
                {field: 'code', title: '策略编码', operate: 'LIKE'},
                {field: 'name', title: '策略名称', operate: 'LIKE'},
                {field: 'company', title: '公司', operate: 'LIKE'},
                {field: 'business_unit', title: '业务板块', operate: 'LIKE'},
                {field: 'market_scope', title: '市场范围', searchList: Config.marketScopeList, formatter: Table.api.formatter.status},
                {field: 'product_line', title: '产品线'},
                {field: 'region_code', title: '区域'},
                {field: 'customer_level', title: '客户等级'},
                {field: 'agent_level', title: '代理等级'},
                {field: 'customer_id', title: '指定客户ID'},
                {field: 'agent_id', title: '指定代理商ID'},
                {field: 'target_type', title: '目标类型', searchList: Config.targetTypeList, formatter: function (value) {
                    var text = (Config.targetTypeList && Config.targetTypeList[value]) || value;
                    return $('<span>').text(text == null ? '' : String(text)).html();
                }},
                {field: 'target_id', title: '目标ID', operate: false},
                {field: 'currency', title: '币种'},
                {field: 'unit', title: '单位'},
                {field: 'guide_price', title: '指导价', formatter: CpqCommon.moneyFormatter, operate: false},
                {field: 'line_floor', title: '产线控制价', formatter: CpqCommon.moneyFormatter, operate: false}
            ];
            if (Config.canViewCost) {
                columns.push({field: 'cost', title: '成本价', formatter: CpqCommon.moneyFormatter, operate: false});
            }
            if (Config.canViewCompanyFloor) {
                columns.push({field: 'company_floor', title: '公司控制价', formatter: CpqCommon.moneyFormatter, operate: false});
            }
            columns.push(
                {field: 'priority', title: '优先级', operate: false},
                {field: 'effective_date', title: '生效日期', operate: 'RANGE', addclass: 'datetimerange', formatter: Table.api.formatter.date},
                {field: 'expiry_date', title: '失效日期', operate: 'RANGE', addclass: 'datetimerange', formatter: Table.api.formatter.date},
                {field: 'version', title: '版本', operate: false},
                {field: 'status', title: '状态', searchList: Config.statusList, formatter: Table.api.formatter.status, custom: CpqCommon.statusCustom},
                {field: 'operate', title: __('Operate'), table: table, events: CpqCommon.operateEvents(), buttons: [CpqCommon.detailButton(baseUrl)].concat(CpqCommon.versionGuardButtons()).concat(CpqCommon.versionButtons(baseUrl, auth).map(function (button) {
                    return button.name === 'publish' ? publishButton(baseUrl, auth, '价格策略') : button;
                })), formatter: Table.api.formatter.operate}
            );
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                sortOrder: 'desc',
                fixedColumns: true,
                fixedNumber: 2,
                columns: [columns]
            });
            Table.api.bindevent(table);
            CpqCommon.versionDiffToolbar('#toolbar', table, {
                code: '策略编码',
                name: '策略名称',
                company: '公司',
                business_unit: '业务板块',
                market_scope: '市场范围',
                region_code: '区域编码',
                customer_level: '客户等级',
                agent_level: '代理等级',
                customer_id: '客户ID',
                agent_id: '指定代理商ID',
                product_line: '产品线',
                target_type: '目标类型',
                target_id: '目标ID',
                currency: '币种',
                unit: '单位',
                guide_price: '指导价',
                line_floor: '产线控制价',
                cost: '成本价',
                company_floor: '公司控制价',
                priority: '优先级',
                effective_date: '生效日期',
                expiry_date: '失效日期',
                version: '版本',
                status: '状态'
            });
            CpqCommon.importPreviewToolbar('#toolbar', baseUrl, auth);
        },
        add: function () { Controller.api.bindevent(); },
        edit: function () { Controller.api.bindevent(); },
        detail: function () {
            Controller.api.bindevent();
            CpqCommon.bindDetail();
        },
        api: {bindevent: function () { Form.api.bindevent($('form[role=form]')); }}
    };
    return Controller;
});
