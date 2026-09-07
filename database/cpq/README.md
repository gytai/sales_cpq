# CPQ 数据库脚本

- `install.sql`：全量安装基线，一步建齐 FastAdmin 基础表（含初始数据）与 CPQ 业务表，`__PREFIX__` 会由安装命令替换为当前数据库前缀。基线为裸 `CREATE TABLE`（非幂等），仅限空库执行。
- `upgrades/`：后续版本的增量升级脚本，文件名使用递增版本号。
- `demo.sql`：仅允许保存脱敏演示数据，不得包含真实客户、物料或价格。

安装命令（`--demo` 附带演示数据）：

```bash
php think install --prefix fa_
php think install --prefix fa_ --demo
```

基线仅限空库；重复安装由 `install.lock` 拦截，重装需先清库并加 `--force=true`。
