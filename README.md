# 社区便民留言板

基于 PHP 原生开发的社区便民留言板网站，支持居民求助、意见建议、失物招领等功能。

## 功能特性

- **首页展示**：滚动显示最新留言信息，统计各类留言数量
- **留言发布**：用户可提交留言，选择类型（求助/建议/失物招领），支持图片上传
- **排序筛选**：支持按时间/热度排序，按类型筛选
- **后台管理**：管理员可审核、通过、拒绝、删除留言
- **批量审核**：多选待审留言批量通过/拒绝，支持统一处理意见、提交前预览影响条目，逐条反馈结果，部分失败不回滚成功项
- **响应式布局**：适配手机和电脑端

## 技术栈

- **后端**：PHP 原生开发
- **数据库**：MySQL
- **前端**：HTML5 + CSS3 + JavaScript（原生）
- **特性**：响应式设计、图片上传、分页、搜索

## 安装部署

### 环境要求

- PHP >= 7.4
- MySQL >= 5.7
- Apache / Nginx

### 安装步骤

1. 将项目文件上传到 Web 服务器根目录

2. 访问安装脚本创建数据库：
   ```
   http://your-domain/install.php
   ```

3. 安装完成后删除 `install.php` 文件

4. 访问首页：
   ```
   http://your-domain/index.php
   ```

5. 访问后台：
   ```
   http://your-domain/admin/login.php
   ```

### 默认管理员账号

- 用户名：`admin`
- 密码：`admin123`

## 项目结构

```
label-9900013/
├── index.php              # 首页
├── submit.php             # 发布留言页
├── detail.php             # 留言详情页
├── install.php            # 安装脚本
├── config/
│   └── database.php       # 数据库配置
├── includes/
│   ├── functions.php      # 公共函数
│   ├── header.php         # 前台头部
│   └── footer.php         # 前台底部
├── api/
│   └── submit.php         # 留言提交API
├── admin/
│   ├── index.php          # 后台管理页
│   ├── login.php          # 后台登录
│   ├── api.php            # 后台API
│   ├── logout.php         # 退出登录
│   └── header.php         # 后台头部
├── assets/
│   ├── css/
│   │   └── style.css      # 样式文件
│   └── js/
│       └── main.js        # 脚本文件
└── uploads/               # 图片上传目录
```

## 数据库配置

编辑 `config/database.php` 文件：

```php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'community_board');
```

## 使用说明

### 前台功能

1. **浏览留言**：首页展示所有已审核通过的留言
2. **筛选类型**：点击类型标签筛选特定类型的留言
3. **排序方式**：支持按时间或热度排序
4. **发布留言**：点击"发布留言"进入提交页面
5. **查看详情**：点击留言卡片查看完整内容

### 后台管理

1. 登录后台管理系统
2. 查看所有留言（支持状态、类型筛选和关键词搜索）
3. 审核留言（单条通过/拒绝，或勾选多条进行批量通过/批量拒绝并填写统一处理意见）
   - 批量提交前会预览所有受影响条目；提交后逐条反馈成功/状态已变化/不存在/失败
   - 部分条目状态已变化时跳过该条，不回滚其它成功项；失败条目保留选择可直接重试
4. 删除不当留言
5. 查看留言详情（含审核意见）

### 批量审核数据库升级（已有系统）

旧库 `messages` 表没有审核意见字段，执行迁移脚本后即可使用批量审核：

```sql
SOURCE database/migration_add_audit_note.sql;
```

新安装（`install.php` / `cli_install.php`）已自动包含该字段。

## 注意事项

- 安装完成后务必删除 `install.php`
- 修改默认管理员密码
- 确保 `uploads/` 目录有写入权限
- 建议配置 HTTPS 保障数据传输安全
