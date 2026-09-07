# ADR 0002：PDF 与 Excel 组件选型

- 状态：已接受（M0，2026-09-03）
- 上下文：方案 §7.6 要求报价 PDF 支持中英文渲染、异步生成、文件哈希与历史保留；Excel 导入导出复用 FastAdmin 生态。

## 决策

1. PDF 采用 `mpdf/mpdf:^8.1`（锁文件固定 8.2.x）：纯 PHP 实现，与 PHP 7.4 / ThinkPHP 5.0 进程内兼容，无外部服务依赖，中文通过内嵌字体渲染，适合 Docker 私有部署一次性打包。
2. Excel 复用仓库已有的 `phpoffice/phpspreadsheet`（锁定 1.30.1），不新增 PhpExcel 等旧组件。
3. M0 仅验证组件可运行（PoC 级渲染与读写），报价 PDF 模板、异步任务、文件哈希等业务能力在 M2 实现，不在本决策范围。

## 后果

- `composer.json` 新增 `mpdf/mpdf:^8.1` 为正式依赖，纳入锁文件与 Docker 镜像层。
- PDF 生成后续必须走队列异步任务（方案 §11.1：30 秒内完成），PoC 只做同步渲染验证。

## PoC 结论

见 `docs/cpq/m0-poc.md`：mpdf 8.2 中英文渲染、PhpSpreadsheet 1.30.1 读写，均在 `php:7.4.33` 容器内验证通过。
