define(['jquery', 'bootstrap', 'backend', 'fast'], function ($, undefined, Backend, Fast) {
    var currentSchema = null;

    // 仅做展示与交互：配置合法性、价格与 BOM 均以后端返回为准，不在此处实现业务规则。

    function groupName(code) {
        var name = code;
        $.each((currentSchema && currentSchema.groups) || [], function (_, group) {
            if (group.code === code) {
                name = group.name;
                return false;
            }
        });
        return name;
    }

    // 将后端 issue.path（如 configuration.features 或 features）解析为页面上的配置组编码
    function resolveGroupCode(path) {
        if (!path) {
            return '';
        }
        var code = String(path).split('.').pop();
        return $('.cpq-group[data-group-code="' + code + '"]').length ? code : '';
    }

    // 滚动定位到指定配置组并给出视觉反馈
    function locateGroup(code) {
        var panel = $('.cpq-group[data-group-code="' + code + '"]');
        if (!panel.length) {
            return;
        }
        $('#cpq-group-nav .list-group-item').removeClass('active');
        $('#cpq-group-nav .list-group-item[data-group-code="' + code + '"]').addClass('active');
        var node = panel.get(0);
        if (node && node.scrollIntoView) {
            node.scrollIntoView({behavior: 'smooth', block: 'center'});
        }
        panel.stop(true).css('opacity', 0.4).animate({opacity: 1}, 600);
        var firstInput = panel.find('input').first();
        if (firstInput.length) {
            firstInput.trigger('focus');
        }
    }

    function clearHighlights() {
        $('.cpq-group').removeClass('panel-danger panel-warning').addClass('panel-default');
        $('#cpq-group-nav .cpq-nav-flag').empty();
    }

    function renderNav(schema) {
        var nav = $('#cpq-group-nav').empty();
        $.each(schema.groups || [], function (_, group) {
            var item = $('<a href="javascript:;" class="list-group-item"></a>').attr('data-group-code', group.code);
            item.append('<span class="cpq-nav-flag pull-right"></span>');
            item.append(document.createTextNode(group.name));
            if (group.is_required) {
                item.append(' ').append('<span class="label label-danger">必选</span>');
            }
            item.on('click', function () {
                locateGroup(group.code);
            });
            nav.append(item);
        });
    }

    function renderGroups(schema) {
        var container = $('#cpq-groups').empty();
        $.each(schema.groups || [], function (_, group) {
            var panel = $('<div class="panel panel-default cpq-group"></div>').attr('data-group-code', group.code);
            var heading = $('<div class="panel-heading"></div>');
            heading.append($('<strong></strong>').text(group.name));
            heading.append($('<code class="pull-right"></code>').text(group.code));
            if (group.is_required) {
                heading.append(' ').append('<span class="label label-danger">必选</span>');
            }
            var body = $('<div class="panel-body"></div>');
            if (group.help_text) {
                body.append($('<p class="help-block"></p>').text(group.help_text));
            }

            if (group.input_type === 'single') {
                $.each(group.options || [], function (_, option) {
                    var label = $('<label class="radio-inline"></label>');
                    var input = $('<input type="radio">').attr({name: 'cpq-' + group.code, value: option.code});
                    if (group.default_value === option.code) {
                        input.prop('checked', true);
                    }
                    label.append(input).append(document.createTextNode(' ' + option.name));
                    body.append(label);
                });
            } else if (group.input_type === 'multiple') {
                var defaults = $.isArray(group.default_value) ? group.default_value : [];
                $.each(group.options || [], function (_, option) {
                    var label = $('<label class="checkbox-inline"></label>');
                    var input = $('<input type="checkbox">').attr({name: 'cpq-' + group.code, value: option.code});
                    if ($.inArray(option.code, defaults) !== -1) {
                        input.prop('checked', true);
                    }
                    label.append(input).append(document.createTextNode(' ' + option.name));
                    body.append(label);
                });
            } else if (group.input_type === 'number') {
                body.append($('<input type="number" step="any" class="form-control">')
                    .attr('name', 'cpq-' + group.code)
                    .val(group.default_value === null ? '' : group.default_value));
            } else if (group.input_type === 'text') {
                body.append($('<input type="text" class="form-control">')
                    .attr('name', 'cpq-' + group.code)
                    .val(group.default_value === null ? '' : group.default_value));
            } else {
                body.append($('<input type="text" class="form-control" readonly>')
                    .attr('name', 'cpq-' + group.code)
                    .val(group.default_value === null ? '' : group.default_value));
            }
            panel.append(heading).append(body);
            container.append(panel);
        });

        renderNav(schema);
        clearHighlights();
        $('#cpq-model-title').text(schema.model.code + ' - ' + schema.model.name);
        $('#cpq-empty').addClass('hidden');
        $('#cpq-workspace').removeClass('hidden');
        $('#cpq-reset,#cpq-validate').prop('disabled', false);
        updateSummary();
    }

    function collectConfiguration() {
        var configuration = {};
        if (!currentSchema) {
            return configuration;
        }
        $.each(currentSchema.groups || [], function (_, group) {
            var name = 'cpq-' + group.code;
            if (group.input_type === 'multiple') {
                var selected = [];
                $('[name="' + name + '"]:checked').each(function () {
                    selected.push($(this).val());
                });
                if (selected.length) {
                    configuration[group.code] = selected;
                }
            } else if (group.input_type !== 'readonly') {
                var field = $('[name="' + name + '"]');
                var value = group.input_type === 'single' ? field.filter(':checked').val() : field.val();
                if (value !== undefined && value !== '') {
                    configuration[group.code] = value;
                }
            }
        });
        return configuration;
    }

    function updateSummary(configuration) {
        $('#cpq-summary').text(JSON.stringify(configuration || collectConfiguration(), null, 2));
    }

    // 把后端校验错误/警告定位到具体配置组：面板变色、导航角标、列表可点击跳转
    function applyIssueHighlights(validation) {
        clearHighlights();
        var firstErrorCode = '';

        function mark(issues, panelClass, flagClass, flagText) {
            $.each(issues || [], function (_, issue) {
                var code = resolveGroupCode(issue.path);
                if (!code) {
                    return;
                }
                var panel = $('.cpq-group[data-group-code="' + code + '"]');
                // 错误优先级高于警告，已标红的组不再降级为警告色
                if (!panel.hasClass('panel-danger')) {
                    panel.removeClass('panel-default panel-warning').addClass(panelClass);
                }
                var flag = $('#cpq-group-nav .list-group-item[data-group-code="' + code + '"] .cpq-nav-flag');
                if (flagClass === 'label-danger' || flag.find('.label-danger').length === 0) {
                    flag.html('<span class="label ' + flagClass + '">' + flagText + '</span>');
                }
                if (panelClass === 'panel-danger' && !firstErrorCode) {
                    firstErrorCode = code;
                }
            });
        }

        mark(validation.errors, 'panel-danger', 'label-danger', '错误');
        mark(validation.warnings, 'panel-warning', 'label-warning', '警告');
        if (firstErrorCode) {
            locateGroup(firstErrorCode);
        }
    }

    function renderIssues(validation) {
        var target = $('#cpq-validation').empty();
        var badge = validation.is_valid
            ? '<span class="label label-success">配置合法</span>'
            : '<span class="label label-danger">配置不合法</span>';
        target.append(badge);

        function appendIssues(title, issues, className) {
            if (!issues || !issues.length) {
                return;
            }
            target.append($('<h5></h5>').text(title));
            var list = $('<ul></ul>').addClass(className);
            $.each(issues, function (_, issue) {
                var text = issue.message + (issue.rule_code ? ' [' + issue.rule_code + ']' : '');
                var item = $('<li></li>');
                var code = resolveGroupCode(issue.path);
                if (code) {
                    var link = $('<a href="javascript:;" title="点击定位到配置组"></a>')
                        .text('[' + groupName(code) + '] ' + text);
                    link.on('click', function () {
                        locateGroup(code);
                    });
                    item.append(link);
                } else {
                    item.text(text);
                }
                list.append(item);
            });
            target.append(list);
        }

        appendIssues('错误', validation.errors, 'text-danger');
        appendIssues('警告', validation.warnings, 'text-warning');
        if (validation.configuration_hash) {
            target.append($('<p class="help-block" style="word-break:break-all"></p>').text('配置指纹：' + validation.configuration_hash));
        }
    }

    function renderBom(lines) {
        var body = $('#cpq-bom').empty();
        if (!lines || !lines.length) {
            body.append('<tr><td colspan="3" class="text-muted">暂无数据</td></tr>');
            return;
        }
        $.each(lines, function (_, line) {
            var row = $('<tr></tr>');
            row.append($('<td></td>').text(line.material_code));
            row.append($('<td></td>').text(line.quantity));
            row.append($('<td></td>').text(line.unit));
            body.append(row);
        });
    }

    function applyVisibility(hiddenGroups) {
        $('.cpq-group').removeClass('hidden');
        $('#cpq-group-nav .list-group-item').removeClass('hidden');
        $.each(hiddenGroups || [], function (_, code) {
            $('.cpq-group[data-group-code="' + code + '"]').addClass('hidden');
            $('#cpq-group-nav .list-group-item[data-group-code="' + code + '"]').addClass('hidden');
        });
    }

    var Controller = {
        index: function () {
            $(document).on('click', '#cpq-load', function () {
                var modelId = $('#cpq-model').val();
                if (!modelId) {
                    Toastr.error('请选择产品型号');
                    return;
                }
                Fast.api.ajax({url: 'cpq/configurator/schema', type: 'get', data: {model_id: modelId}}, function (schema) {
                    currentSchema = schema;
                    renderGroups(schema);
                    $('#cpq-validation').text('尚未校验');
                    renderBom([]);
                    return false;
                });
            });

            $(document).on('click', '#cpq-reset', function () {
                if (currentSchema) {
                    renderGroups(currentSchema);
                    applyVisibility([]);
                    $('#cpq-validation').text('尚未校验');
                    renderBom([]);
                }
            });

            $(document).on('change input', '#cpq-groups input', function () {
                updateSummary();
            });

            $(document).on('click', '#cpq-validate', function () {
                var modelId = $('#cpq-model').val();
                Fast.api.ajax({
                    url: 'cpq/configurator/validateConfiguration',
                    type: 'post',
                    data: {model_id: modelId, configuration: JSON.stringify(collectConfiguration())}
                }, function (validation) {
                    renderIssues(validation);
                    applyIssueHighlights(validation);
                    renderBom(validation.bom);
                    applyVisibility(validation.hidden_groups);
                    updateSummary(validation.configuration);
                    return false;
                });
            });
        }
    };
    return Controller;
});
