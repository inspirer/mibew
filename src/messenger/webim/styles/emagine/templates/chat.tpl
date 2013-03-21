<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.0 Transitional//EN">
<html>
<head>
<title>${msg:chat.window.title.agent}</title>
<link rel="shortcut icon" href="${webimroot}/images/favicon.ico" type="image/x-icon">
<link rel="stylesheet" type="text/css" href="${tplroot}/chat.css">
<link href='http://fonts.googleapis.com/css?family=Ubuntu:400,700' rel='stylesheet' type='text/css'>
<script type="text/javascript" language="javascript" src="${webimroot}/js/${jsver}/common.js"></script>
<script type="text/javascript" language="javascript" src="${webimroot}/js/${jsver}/brws.js"></script>
<script type="text/javascript" language="javascript"><!--
var threadParams = { servl:"${webimroot}/thread.php",wroot:"${webimroot}",frequency:${page:frequency},${if:user}user:"true",${endif:user}threadid:${page:ct.chatThreadId},token:${page:ct.token},cssfile:"${tplroot}/chat.css",ignorectrl:${page:ignorectrl} };
//-->
</script>
<script type="text/javascript" language="javascript" src="${webimroot}/js/${jsver}/chat.js"></script>
</head>
<body style="background-image: url(${tplroot}/images/bg.gif); margin: 0px;">
<div style="background-image: url(${tplroot}/images/banner.png);"></div>
${if:ct.company.chatLogoURL}
    ${if:webimHost}
        <a class="logo-img" onclick="window.open('${page:webimHost}');return false;" href="${page:webimHost}">
            <img src="${page:ct.company.chatLogoURL}" border="0" alt="">
        </a>
    ${else:webimHost}
        <a  class="logo-img" href="#">
            <img src="${page:ct.company.chatLogoURL}" border="0" alt="">
        </a>
    ${endif:webimHost}
${endif:ct.company.chatLogoURL}

${ifnot:ct.company.chatLogoURL}
    ${if:webimHost}
        <a class="logo-txt" onclick="window.open('${page:webimHost}');return false;" href="${page:webimHost}">${page:ct.company.name}</a>
    ${else:webimHost}
        <a class="logo-txt" href="#">${page:ct.company.name}</a>
    ${endif:webimHost}
${endif:ct.company.chatLogoURL}
<div class="menu-top">
    <label>${msg:chat.window.product_name}</label>
    <a href="${msg:site.url}" title="${msg:company.title}" target="_blank">${msg:site.title}</a>
    <a class="closethread" href="javascript:void(0)" onclick="return false;" title="${msg:chat.window.close_title}"></a>
</div>
<div class="menu-main">
${if:agent}
    ${if:historyParams}
        ${msg:chat.window.chatting_with}
            <a href="${page:historyParamsLink}" target="_blank" title="${msg:page.analysis.userhistory.title}" onclick="this.newWindow = window.open('${page:historyParamsLink}', 'UserHistory', 'toolbar=0,scrollbars=0,location=0,statusbar=1,menubar=0,width=703,height=380,resizable=1');this.newWindow.focus();this.newWindow.opener=window;return false;">${page:ct.user.name}</a>
        ${else:historyParams}
            ${msg:chat.window.chatting_with} <b>${page:ct.user.name}</b>
        ${endif:historyParams}
${endif:agent}
${if:user}
	${if:canChangeName}
        <div id="changename1" style="display:${page:displ1};">
            <label>${msg:chat.client.name}</label>
            <div class="name-editor">
                <a href="javascript:void(0)" onclick="return false;" title="${msg:chat.client.changename}">ok</a>
                <input id="uname" type="text" size="12" value="${page:ct.user.name}" class="field">
            </div>
        </div>
        <div id="changename2" style="display:${page:displ2};">
            <a id="unamelink" href="javascript:void(0)" onclick="return false;" title="${msg:chat.client.changename}">${page:ct.user.name}</a>
            <a class="change-user" href="javascript:void(0)" onclick="return false;" title="${msg:chat.client.changename}"></a>
        </div>
	${else:canChangeName}
            ${msg:chat.client.name}&nbsp;${page:ct.user.name}
	${endif:canChangeName}
${endif:user}
${if:agent}
    <a id="main-close" class="closethread" href="javascript:void(0)" onclick="return false;" title="${msg:chat.window.close_title}"></a>
${endif:agent}

${if:user}
    <a class="mail-history" href="${page:mailLink}&amp;style=${styleid}" target="_blank" title="${msg:chat.window.toolbar.mail_history}" onclick="this.newWindow = window.open('${page:mailLink}&amp;style=${styleid}', 'ForwardMail', 'toolbar=0,scrollbars=0,location=0,statusbar=1,menubar=0,width=603,height=254,resizable=0'); if (this.newWindow != null) {this.newWindow.focus();this.newWindow.opener=window;}return false;"></a>
${endif:user}
${if:agent}
${if:canpost}
    <a id="redirect-to" href="${page:redirectLink}&amp;style=${styleid}" title="${msg:chat.window.toolbar.redirect_user}"></a>
${endif:canpost}
${if:historyParams}
    <a id="visit-history" href="${page:historyParamsLink}" target="_blank" title="${msg:page.analysis.userhistory.title}" onclick="this.newWindow = window.open('${page:historyParamsLink}', 'UserHistory', 'toolbar=0,scrollbars=0,location=0,statusbar=1,menubar=0,width=720,height=480,resizable=1');this.newWindow.focus();this.newWindow.opener=window;return false;"></a>
${endif:historyParams}
${endif:agent}
    <a id="togglesound" href="javascript:void(0)" onclick="return false;" title="Turn off sound">
        <img id="soundimg" class="tplimage isound" src="${webimroot}/images/free.gif" border="0" alt="Sound&nbsp;" /></a>
    <a id="refresh" href="javascript:void(0)" onclick="return false;" title="${msg:chat.window.toolbar.refresh}"></a>
${if:sslLink}
    <a href="${page:sslLink}&amp;style=${styleid}" title="SSL" >SSL</a>
${endif:sslLink}
</div>
<div id="engineinfo" style="display:none;"></div>
<div class="clearfix"></div>
<!--img class="tplimageloc ilog" src="${webimroot}/images/free.gif" border="0" alt="" /-->
<div class="chat-history">
    <iframe id="chatwnd" width="100%" height="100%" src="${if:neediframesrc}${webimroot}/images/blank.html${endif:neediframesrc}" frameborder="0">
    Sorry, your browser does not support iframes; try a browser that supports W3 standards.
    </iframe>
</div>
${if:canpost}
<div class="chat-message">
    <a id="sndmessagelnk" href="javascript:void(0)" onclick="return false;" title="${msg:chat.window.send_message}">${msg:chat.window.send_message_short,send_shortcut}</a>
    <textarea id="msgwnd" class="message" tabindex="0"></textarea>
    <div id="postmessage"></div>
    <div id="typingdiv" style="display:none;">${msg:typing.remote}</div>
    <div id="avatarwnd"></div>
</div>
${endif:canpost}

${if:agent}${if:canpost}
    <select id="predefined" size="1" class="answer">
    <option>${msg:chat.window.predefined.select_answer}</option>
    ${page:predefinedAnswers}
    </select>
${endif:canpost}${endif:agent}
<div id="poweredBy">
    ${msg:chat.window.poweredby} 
    <a id="poweredByLink" href="http://mibew.org" title="Mibew Community" target="_blank">mibew.org</a>
</div>
</body>
</html>