# CPQ 数据库脚本

- `install.sql`：创建 CPQ 业务表，`__PREFIX__` 会由安装命令替换为当前 FastAdmin 数据库前缀。
- `upgrades/`：后续版本的增量升级脚本，文件名使用递增版本号。
- `demo.sql`：仅允许保存脱敏演示数据，不得包含真实客户、物料或价格。

安装命令：

```bash
php think cpq:install
php think cpq:install --demo
```

脚本使用 `CREATE TABLE IF NOT EXISTS`，但执行前仍应备份已有数据库。
