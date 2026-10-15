<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>上游订阅 · {{ $title }}</title>
    <link rel="stylesheet" href="/assets/upstream/manage.css">
    <script defer src="/assets/upstream/manage.js"></script>
</head>
<body data-admin-path="{{ $secure_path }}">
<header class="masthead"><a href="/{{ $secure_path }}">{{ $title }} <span>/ 管理后台</span></a><span class="eyebrow">连接管理</span></header>
<main>
    <div class="heading"><div><p class="eyebrow">UPSTREAM CONNECTIONS</p><h1>上游订阅</h1><p>一个入口，连接你的上游。用户继续使用原来的订阅。</p></div><button id="new-binding" type="button">添加上游 <span aria-hidden="true">＋</span></button></div>
    <div id="notice" role="status" aria-live="polite" hidden></div>
    <section id="login-required" class="empty" hidden><h2>请先登录管理后台</h2><p>这里沿用你现有的管理员账号。</p><a class="button" href="/{{ $secure_path }}">前往登录</a></section>
    <section id="workspace" hidden>
        <div class="section-title"><h2>已绑定的入口</h2><button id="reload" class="secondary" type="button">刷新状态</button></div>
        <div class="table-scroll"><table><caption class="sr-only">上游订阅及连接状态</caption><thead><tr><th scope="col">入口节点</th><th scope="col">连接状态</th><th scope="col">上游协议</th><th scope="col">最近确认</th><th scope="col">操作</th></tr></thead><tbody id="bindings"></tbody></table></div>
        <div id="empty" class="empty" hidden><span class="empty-symbol" aria-hidden="true">↗</span><h2>添加第一个上游</h2><p>先准备一个独立的入口节点，再粘贴订阅地址进行绑定。</p></div>
        <aside class="note">用户流量与限速沿用入口节点的套餐设置。入口服务首次安装完成后，上游的更新和停用都可以在这里操作。</aside>
    </section>
</main>
<dialog id="editor" aria-labelledby="editor-title">
    <form id="binding-form">
        <div class="dialog-heading"><h2 id="editor-title">添加上游</h2><button type="button" class="icon secondary" data-close="editor" aria-label="关闭">×</button></div>
        <input id="binding-id" type="hidden">
        <label for="entrance">入口节点</label><select id="entrance" required></select>
        <p class="hint">只绑定专用于上游转发的独立入口；原有直连节点请保持原配置。</p>
        <label for="source">订阅地址或节点链接</label><textarea id="source" rows="3" maxlength="16384" autocomplete="off" spellcheck="false" placeholder="https://… 或 vless://…"></textarea>
        <p id="source-hint" class="hint">链接加密保存，不会出现在用户订阅中。</p>
        <div class="field-row"><div><label for="interval">更新间隔</label><select id="interval"><option value="300">5 分钟</option><option value="1800">30 分钟</option><option value="3600" selected>1 小时</option><option value="21600">6 小时</option><option value="86400">24 小时</option></select></div><div><label for="selector">上游节点名称（选填）</label><input id="selector" maxlength="255" placeholder="只有一个节点时留空"></div></div>
        <label class="checkbox"><input id="enabled" type="checkbox">启用这个上游</label>
        <p id="form-error" class="form-error" role="alert"></p>
        <footer><button type="button" class="secondary" data-close="editor">取消</button><button id="save" type="submit">保存绑定</button></footer>
    </form>
</dialog>
<dialog id="provision" aria-labelledby="provision-title">
    <div class="dialog-heading"><h2 id="provision-title">连接入口服务器</h2><button type="button" class="icon secondary" data-close="provision" aria-label="关闭">×</button></div>
    <p>下载入口服务配置，交给服务器上的上游服务使用。令牌仅在这里显示一次，请妥善保存文件。</p>
    <p class="hint">配置只包含入口接入令牌，不包含上游订阅。首次安装步骤见项目的上游网关文档。</p>
    <footer><button type="button" class="secondary" data-close="provision">关闭</button><button id="download-config" type="button">下载接入配置</button></footer>
</dialog>
</body>
</html>
