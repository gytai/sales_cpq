# M2 客户、价格管理与模拟器前端验证（GYTAI-74）

验证日期：2026-09-04。页面沿用 FastAdmin 原生 Bootstrap Table / Form / RequireJS 基线。

## 页面范围

- P30–P37：价格表、价格条目、价格矩阵与三层控制价、价格规则、汇率/税率/费用、价格模拟器、发布版本；
- P40–P45：客户与详情、客户/代理等级、代理商与详情、销售区域树、销售组织树及成员；
- 列表支持多维筛选、版本对比、发布生命周期、覆盖缺口、导入预览、发布影响计数与回滚；
- P36 直接调用后台 Pricing API，展示服务端 Decimal 金额字符串、14 步规则轨迹、快照、控制价分级和整单审批级别。

## 安全与金额边界

- `PricePolicy::index` 在响应层通过 `SensitiveFieldService::maskRows` 删除无权字段；模板按角色决定是否创建成本/公司控制价输入，列表 JS 按角色决定是否创建列；
- `Pricing::calculate/explain` 在响应层执行 `maskForRoles`，前端只缓存该脱敏响应，安全轨迹导出同样取自该缓存；
- 页面不使用 `parseFloat`、`toFixed`、`Math.round` 等处理最终金额；最终金额完全采用服务端 Decimal 字符串。

## 自动化结果

- `php tests/cpq/frontend_m2.php`：PASS（42 assertions）；
- `tests/cpq/price_channel.php`：PASS（82 assertions）；
- `tests/cpq/pricing.php`：PASS（140 assertions）；
- `tests/cpq/master_data.php`：PASS（66 assertions）；
- `tests/cpq/rule_analysis.php`：PASS（33 assertions）；
- `tests/cpq/rule_engine.php`：PASS（26 assertions）；
- `tests/cpq/upgrade.php`：PASS（33 assertions）；
- `tests/cpq/integration.php`：PASS；
- 全部 CPQ PHP 文件 `php -l`、全部 CPQ 页面脚本 `node --check`：PASS。

浏览器脚本 `tests/cpq/browser/m2_check.js` 真实登录后台后通过：管理员完整字段、销售角色 DOM/列表响应脱敏、区域树、Decimal 总额、14 步轨迹、0.9/0.85/0.75 三层控制价边界、页面无 5xx/JS 异常。截图由脚本生成并随工单交付。
