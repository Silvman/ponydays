{assign var="sidebarPosition" value='left'}
{include file='header.tpl'}


{include file='actions/ActionProfile/profile_top.tpl'}
{include file='menu.settings.tpl'}


{hook run='settings_behavior_begin'}

<form action="{router page='settings'}behavior/" method="POST" enctype="multipart/form-data" id="behavior-form">
	{hook run='form_settings_behavior_begin'}

	<input type="hidden" name="security_ls_key" value="{$LIVESTREET_SECURITY_KEY}" />
	
	<label for="min_comment_width">{$aLang.min_comment_width_behavior_label}</label>
	<input type="number" min=100 max=700 value=250 name="min_comment_width" class="input-width-300" data-save=1 /><br/>
    {literal}
	<script>
	    $('#behavior-form  [data-save=1]').each(function(k,v){v.value = localStorage.getItem(v.name)});
	    
	    function saveBehaviorSettings(e) {
	        $('#behavior-form  [data-save=1]').each(function(k,v){localStorage.setItem(v.name, v.value)})
	        ls.msg.notice('','Настройки сохранены')
	        return false;
	    }
	</script>
	{/literal}
	
	<button type="submit" name="submit_settings_behavior" onclick="saveBehaviorSettings(); return false;" class="button button-primary">{$aLang.settings_profile_submit}</button>
</form>

{hook run='settings_behavior_end'}

{include file='footer.tpl'}