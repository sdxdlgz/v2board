'use strict';
(() => {
    const $ = id => document.getElementById(id);
    const path = document.body.dataset.adminPath;
    const base = `/api/v1/${path}/server/upstream/`;
    const errors = {source_fetch:'订阅获取失败',core_start:'连接服务启动失败',invalid_subscription:'订阅格式或协议不受支持',select_node:'请填写订阅中要使用的节点名称',probe_failed:'上游连接测试失败',panel_unavailable:'面板暂不可达',unsupported_core:'连接服务版本不匹配',internal:'服务内部错误'};
    let items = [], entrances = [], provision = null;

    async function api(action, data) {
        const authorization = localStorage.getItem('authorization');
        if (!authorization) throw new Error('请先登录管理后台');
        const response = await fetch(base + action, {
            method: data ? 'POST' : 'GET', cache:'no-store',
            headers: {'authorization':authorization, 'Content-Type':'application/json', 'Accept':'application/json'},
            ...(data ? {body:JSON.stringify(data)} : {})
        });
        const result = await response.json();
        if (response.status === 403) {
            $('workspace').hidden = true; $('login-required').hidden = false;
        }
        if (!response.ok) throw new Error(Object.values(result.errors || {}).flat()[0] || result.message || '操作失败，请稍后重试');
        return result.data;
    }
    function notice(message, error = false) {
        $('notice').textContent = message; $('notice').className = error ? 'error' : ''; $('notice').hidden = false;
    }
    function entrance(item) { return entrances.find(n => n.id === item.node_id && n.type === item.node_type); }
    function addText(parent, tag, text, className) {
        const element = document.createElement(tag); element.textContent = text;
        if (className) element.className = className;
        parent.append(element); return element;
    }
    function render() {
        $('bindings').replaceChildren(); $('empty').hidden = items.length > 0;
        for (const item of items) {
            const row = document.createElement('tr');
            const name = addText(row, 'td', entrance(item)?.name || '入口已删除');
            addText(name, 'small', `${item.node_type.toUpperCase()} · #${item.node_id}`);
            const status = addText(row, 'td', '');
            const stale = item.last_seen_at && Date.now()/1000 - item.last_seen_at > 90;
            const label = stale ? '服务离线' : ({ready:'已连接',error:'更新失败',disabled:'已停用',pending:'等待同步'}[item.status] || '等待同步');
            addText(status, 'span', label, `badge ${stale ? 'error' : item.status}`);
            if (item.error_code) addText(status, 'div', errors[item.error_code] || '连接异常', 'error');
            if (item.status === 'error' && item.applied_revision) addText(status, 'small', '保留上一份有效配置');
            addText(row, 'td', item.protocol || '—');
            addText(row, 'td', item.last_seen_at ? new Date(item.last_seen_at*1000).toLocaleString() : '尚未连接');
            const actions = addText(row, 'td', '');
            for (const [label, action] of [['编辑',()=>edit(item)],['立即更新',()=>mutate('refresh',{id:item.id})],['接入配置',()=>rotate(item)]]) {
                const button = addText(actions, 'button', label, 'secondary'); button.type = 'button'; button.addEventListener('click', action);
            }
            if (!item.enabled) {
                const button = addText(actions, 'button', '删除', 'secondary'); button.type = 'button';
                button.addEventListener('click', () => {if (confirm('删除这个已停用的上游绑定？入口节点会保留。')) mutate('drop',{id:item.id});});
            }
            $('bindings').append(row);
        }
    }
    async function load() {
        try {
            const data = await api('fetch'); items = data.items; entrances = data.entrances;
            $('workspace').hidden = false; $('login-required').hidden = true; render();
        } catch (error) { notice(error.message, true); if (!localStorage.getItem('authorization')) $('login-required').hidden = false; }
    }
    async function mutate(action, data) {
        try { await api(action,data); notice('已保存，等待入口服务同步。'); await load(); }
        catch (error) { notice(error.message,true); }
    }
    function edit(item = null) {
        if (!entrances.length && !item) {notice('请先在节点管理中创建一个独立的 Trojan 或 VLESS 入口。',true);return;}
        $('binding-form').reset(); $('form-error').textContent = '';
        $('binding-id').value = item?.id || ''; $('editor-title').textContent = item ? '编辑上游' : '添加上游';
        $('source').required = !item; $('source-hint').textContent = item ? '留空保留原链接；填写新链接将替换上游。' : '链接加密保存，不会出现在用户订阅中。';
        $('entrance').replaceChildren();
        for (const node of entrances) {
            if (!item && items.some(n => n.node_type === node.type && n.node_id === node.id)) continue;
            const option = addText($('entrance'), 'option', `${node.name} · ${node.type.toUpperCase()} #${node.id}`);
            option.value = `${node.type}:${node.id}`;
        }
        if (item && !entrance(item)) {
            const option = addText($('entrance'), 'option', `入口已删除或不再独立 · ${item.node_type.toUpperCase()} #${item.node_id}`);
            option.value = `${item.node_type}:${item.node_id}`;
        }
        $('entrance').disabled = !!item;
        if (item) {
            $('entrance').value = `${item.node_type}:${item.node_id}`; $('selector').value = item.selector || '';
            $('interval').value = String(item.refresh_interval); $('enabled').checked = item.enabled;
        }
        if (!$('entrance').value) {notice('没有可绑定的入口，请先创建新的独立节点。',true);return;}
        $('editor').showModal();
    }
    function showProvision(id, token) {
        provision = {panel_url:location.origin,upstream_id:id,token,mihomo:'/usr/local/bin/mihomo',listen:'127.0.0.1:19090',state_dir:`/var/lib/v2bx-upstream/${id}`,probe_url:'https://www.gstatic.com/generate_204'};
        $('provision').showModal();
    }
    async function rotate(item) {
        if (!confirm('重新生成接入配置会撤销旧令牌，现有入口需更换配置才能继续使用。是否继续？')) return;
        try { const data = await api('rotateToken',{id:item.id}); showProvision(item.id,data.agent_token); }
        catch (error) { notice(error.message,true); }
    }
    $('binding-form').addEventListener('submit', async event => {
        event.preventDefault(); $('save').disabled = true; $('form-error').textContent = '';
        const [node_type,node_id] = $('entrance').value.split(':');
        const data = {node_type,node_id:Number(node_id),source:$('source').value.trim(),selector:$('selector').value.trim(),refresh_interval:Number($('interval').value),enabled:$('enabled').checked};
        if ($('binding-id').value) data.id = Number($('binding-id').value);
        try {
            const result = await api('save',data); $('source').value = ''; $('editor').close();
            notice(data.enabled ? '绑定已保存。入口服务会测试上游，成功后启用。' : '停用已保存，等待入口服务关闭转发。'); await load();
            if (result.agent_token) showProvision(result.item.id,result.agent_token);
        } catch (error) { $('form-error').textContent = error.message; }
        finally { $('save').disabled = false; }
    });
    $('download-config').addEventListener('click', () => {
        if (!provision) return;
        const url = URL.createObjectURL(new Blob([JSON.stringify(provision,null,2)+'\n'],{type:'application/json'}));
        const link = document.createElement('a'); link.href = url; link.download = `upstream-${provision.upstream_id}.json`; link.click();
        setTimeout(()=>URL.revokeObjectURL(url),1000);
    });
    $('provision').addEventListener('close',()=>{provision=null;});
    $('editor').addEventListener('close',()=>{$('source').value='';});
    document.querySelectorAll('[data-close]').forEach(button=>button.addEventListener('click',()=>$(button.dataset.close).close()));
    $('new-binding').addEventListener('click',()=>edit()); $('reload').addEventListener('click',load);
    setInterval(()=>{if (!$('editor').open && !$('provision').open && !$('workspace').hidden) load();},15000);
    load();
})();
