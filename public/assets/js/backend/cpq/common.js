define([], function () {
    return {
        // CPQ 状态徽标统一配色（Bootstrap label 颜色，扩展 FastAdmin 默认 custom 映射）
        statusCustom: {
            draft: 'gray',
            pending: 'warning',
            published: 'success',
            expired: 'danger',
            normal: 'success',
            hidden: 'gray'
        },
        booleanFormatter: function (value) {
            return parseInt(value, 10) === 1
                ? '<span class="label label-success">是</span>'
                : '<span class="label label-default">否</span>';
        },
        directPublishButtons: function (baseUrl) {
            return [{
                name: 'publish',
                text: '发布',
                icon: 'fa fa-check',
                classname: 'btn btn-xs btn-success btn-ajax',
                url: baseUrl + '/publish',
                confirm: '确认发布当前草稿？发布后不可直接修改。',
                refresh: true,
                visible: function (row) {
                    return row.status === 'draft';
                }
            }];
        },
        versionButtons: function (baseUrl) {
            return [
                {
                    name: 'submit',
                    text: '提交',
                    icon: 'fa fa-send',
                    classname: 'btn btn-xs btn-info btn-ajax',
                    url: baseUrl + '/submit',
                    confirm: '确认提交审批？提交后不可直接编辑。',
                    refresh: true,
                    visible: function (row) {
                        return row.status === 'draft';
                    }
                },
                {
                    name: 'publish',
                    text: '发布',
                    icon: 'fa fa-check',
                    classname: 'btn btn-xs btn-success btn-ajax',
                    url: baseUrl + '/publish',
                    confirm: '确认发布当前版本？发布后不可直接修改。',
                    refresh: true,
                    visible: function (row) {
                        return row.status === 'pending';
                    }
                }
            ];
        }
    };
});
