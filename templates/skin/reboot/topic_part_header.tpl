{assign var="oBlog" value=$oTopic->getBlog()}
{assign var="oUser" value=$oTopic->getUser()}
{assign var="oVote" value=$oTopic->getVote()}
{assign var="bAllowLockControl" value=$oTopic->testAllowLockControl($oUserCurrent)}
{assign var="bVoteInfoEnabled" value=$LS->ACL_CheckSimpleAccessLevel(Config::Get('acl.vote_state.comment.ne_enable_level'), $oUserCurrent, $oTopic, 'topic')}


<article class="topic topic-type-{$oTopic->getType()} js-topic {if $bVoteInfoEnabled}voteInfo-enable{/if}">
<div class="smile" style="background-image: url('https://static.lunavod.ru/smiles/{include file="smiles.php"}')"></div>
	<header class="topic-header">
		<h1 class="topic-title word-wrap">
			{if $oTopic->getPublish() == 0}   
				<i class="icon-tag" title="{$aLang.topic_unpublish}"></i>
			{/if}
			
			{if $oTopic->getType() == 'link'} 
				<i class="icon-share-alt" title="{$aLang.topic_link}"></i>
			{/if}
			
			{if $bTopicList}
				<a href="{$oTopic->getUrl()}">{$oTopic->getTitle()|escape:'html'}</a>
			{else}
				{$oTopic->getTitle()|escape:'html'}
			{/if}
		</h1>
		
		
		{if $oTopic->getType() == 'link'}
			<div class="topic-url">
				<a href="{router page='link'}go/{$oTopic->getId()}/" title="{$aLang.topic_link_count_jump}: {$oTopic->getLinkCountJump()}">{$oTopic->getLinkUrl()}</a>
			</div>
		{/if}
		
		
		<div class="topic-info">
			<div style="display: inline; padding-right: 15px;" id="vote_area_topic_{$oTopic->getId()}" class="stickyDa vote topic-vote 
																{if $oVote || ($oUserCurrent && $oTopic->getUserId() == $oUserCurrent->getId()) || strtotime($oTopic->getDateAdd()) < $smarty.now-$oConfig->GetValue('acl.vote.topic.limit_time')}
																	{if $oTopic->getRating() > 0}
																		vote-count-positive
																	{elseif $oTopic->getRating() < 0}
																		vote-count-negative
																	{elseif $oTopic->getRating() == 0 and $oTopic->getCountVote() > 0 and $bVoteInfoEnabled}
																		vote-count-mixed
																	{/if}
																{/if}
																
																{if $oVote} 
																	voted
																	
																	{if $oVote->getDirection() > 0}
																		voted-up
																	{elseif $oVote->getDirection() < 0}
																		voted-down
																	{/if}
																{/if}">
				{if $oVote || ($oUserCurrent && $oTopic->getUserId() == $oUserCurrent->getId()) || strtotime($oTopic->getDateAdd()) < $smarty.now-$oConfig->GetValue('acl.vote.topic.limit_time')}
					{assign var="bVoteInfoShow" value=true}
				{/if}

				<div class="vote-up" onclick="return ls.vote.vote({$oTopic->getId()},this,1,'topic');"><i class="fa fa-arrow-up"></i></div>
				<div class="vote-count {if $bVoteInfoShow}js-infobox-vote-topic{/if}" id="vote_total_topic_{$oTopic->getId()}" title="{$aLang.topic_vote_count}: {$oTopic->getCountVote()}">
						{if $oTopic->getRating() > 0}+{/if}{$oTopic->getRating()}
				</div>
				<div class="vote-down" onclick="return ls.vote.vote({$oTopic->getId()},this,-1,'topic');"><i class="fa fa-arrow-down"></i></div>
				{if $bVoteInfoShow}
					<div id="vote-info-topic-{$oTopic->getId()}" style="display: none;">
						+ {$oTopic->getCountVoteUp()}<br/>
						- {$oTopic->getCountVoteDown()}<br/>
						&nbsp; {$oTopic->getCountVoteAbstain()}<br/>
						{hook run='topic_show_vote_stats' topic=$oTopic}
					</div>
				{/if}
			</div>
			<img width=15 src="{$oUser->getProfileAvatarPath(24)}"><strong><a style="padding-left: 5px;" rel="author" href="{$oUser->getUserWebPath()}">{$oUser->getLogin()}</a></strong> в <a href="{$oBlog->getUrlFull()}" class="topic-blog">{$oBlog->getTitle()|escape:'html'}</a>
			
			<time datetime="{date_format date=$oTopic->getDateAdd() format='c'}" title="{date_format date=$oTopic->getDateAdd() format='j F Y, H:i'}">
				{date_format date=$oTopic->getDateAdd() format="j F Y, H:i"}
			</time>
			
			<ul class="actions">								   
				{if $oUserCurrent and (($oUserCurrent->isGlobalModerator() and $oTopic->getBlog()->getType() == "open") or $oUserCurrent->getId()==$oTopic->getUserId() or $oUserCurrent->isAdministrator() or $oBlog->getUserIsAdministrator() or $oBlog->getUserIsModerator() or $oBlog->getOwnerId()==$oUserCurrent->getId())}
					<li><a href="{cfg name='path.root.web'}/{$oTopic->getType()}/edit/{$oTopic->getId()}/" title="{$aLang.topic_edit}" class="actions-edit">{$aLang.topic_edit}</a></li>
				{/if}
				
				{if $oUserCurrent and (($oUserCurrent->isGlobalModerator() and $oTopic->getBlog()->getType() == "open") or $oUserCurrent->isAdministrator() or $oBlog->getUserIsAdministrator() or $oBlog->getUserIsModerator() or ($oTopic->getUserId() === $oUserCurrent->getId() and !$oTopic->isControlLocked()) or $oBlog->getOwnerId()==$oUserCurrent->getId())}
					<li><a href="{router page='topic'}delete/{$oTopic->getId()}/?security_ls_key={$LIVESTREET_SECURITY_KEY}" title="{$aLang.topic_delete}" onclick="return confirm('{$aLang.topic_delete_confirm}');" class="actions-delete">{$aLang.topic_delete}</a></li>
				{/if}
				
				{if $bAllowLockControl}
					<li><label title="{$aLang.topic_lock_control_title}" class="actions-topic_lock_control"><input type="checkbox" onclick="if(!!this.checked || confirm('{$aLang.topic_lock_control_un}')) return ls.topic.lockControl({$oTopic->getId()},this); else return false;" {if $oTopic->isControlLocked()}checked="checked"{/if} />&nbsp;{$aLang.topic_lock_control}</label></li>
				{/if}
			</ul>
			
			{if !$bAllowLockControl and $oTopic->isControlLocked()}
				<i class="fa fa-paperclip" title="{$aLang.topic_control_locked}"></i>
			{/if}
		</div>
		<div class="voters">
{foreach $aVotes as $vote}<span style="color:{if $vote == 1}green{elseif $vote == 0}gray{elseif $vote == -1}red{/if} ;">{$vote@key}</span> {/foreach}
</div>
	</header>
