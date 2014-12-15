    <div class="sidebar-header"><h3>{str tag="login"}{contextualhelp plugintype='core' pluginname='core' section='loginbox'}</h3></div>
    <div class="sidebar-content">
        <noscript><p>{str tag="javascriptnotenabled"}</p></noscript>
        {dynamic}{insert_messages placement='loginbox'}{/dynamic}
        <!--<div id="loginform_container">{$sbdata.loginform|safe}</div>-->
        
        <span style="color:#3366ff;background-color:#c0c0c0;"><a href="http://essd.bath.ac.uk/mahara_test/auth/cas" target="_self"><span style="color:#3366ff;background-color:#c0c0c0;">Sign in using <strong>University of Bath SSO</strong></span></a></span>
    </div>
