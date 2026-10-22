{**
 * plugins/generic/ACahyaRenameDl/templates/settings.tpl
 *
 * Copyright (c) 2026 ACahya
 * Distributed under the GNU GPL v3.
 *
 * Settings modal form for ACahyaRenameDl plugin
 *}
<script>
	$(function() {ldelim}
		$('#acahyaRenameDlSettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');

		// Handle preset pattern selection
		$('#presetPattern').on('change', function() {ldelim}
			var val = $(this).val();
			var patternInput = $('#namingPattern');
			if (val === 'classic32') {ldelim}
				patternInput.val('{$ldelim}$submissionId{$rdelim}-{$ldelim}$genre{$rdelim}-{$ldelim}$fileId{$rdelim}-{$ldelim}$revision{$rdelim}-{$ldelim}$fileStage{$rdelim}-{$ldelim}$date{$rdelim}.{$ldelim}$ext{$rdelim}');
			{rdelim} else if (val === 'idOriginalName') {ldelim}
				patternInput.val('{$ldelim}$submissionId{$rdelim}-{$ldelim}$originalName{$rdelim}.{$ldelim}$ext{$rdelim}');
			{rdelim} else if (val === 'idGenreOriginalName') {ldelim}
				patternInput.val('{$ldelim}$submissionId{$rdelim}-{$ldelim}$genre{$rdelim}-{$ldelim}$originalName{$rdelim}.{$ldelim}$ext{$rdelim}');
			{rdelim} else if (val === 'idAuthorGenreDate') {ldelim}
				patternInput.val('{$ldelim}$submissionId{$rdelim}-{$ldelim}$authorName{$rdelim}-{$ldelim}$genre{$rdelim}-{$ldelim}$date{$rdelim}.{$ldelim}$ext{$rdelim}');
			{rdelim}
		{rdelim});

		// Clickable tokens
		$('.acahya-token-badge').on('click', function(e) {ldelim}
			e.preventDefault();
			var token = $(this).data('token');
			var input = $('#namingPattern');
			input.val(input.val() + token);
			$('#presetPattern').val('custom');
		{rdelim});
	{rdelim});
</script>

<style>
	.acahya-banner {
		background: #eff6ff;
		border-left: 4px solid #3b82f6;
		padding: 12px 16px;
		margin-bottom: 20px;
		border-radius: 4px;
	}
	.acahya-token-badge {
		display: inline-block;
		background: #f1f5f9;
		border: 1px solid #cbd5e1;
		padding: 2px 8px;
		border-radius: 4px;
		font-family: monospace;
		font-size: 11px;
		color: #1e293b;
		cursor: pointer;
		margin: 2px 3px;
		transition: background 0.15s ease;
	}
	.acahya-token-badge:hover {
		background: #3b82f6;
		color: #fff;
		border-color: #2563eb;
	}
</style>

<form class="pkp_form" id="acahyaRenameDlSettingsForm" method="post" action="{url router=$smarty.const.ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="settings" save=true}">
	{csrf}
	{include file="controllers/notification/inPlaceNotification.tpl" notificationId="acahyaRenameDlSettingsFormNotification"}

	<div class="acahya-banner">
		<h4 style="margin: 0 0 6px 0; color: #1e40af; font-size: 14px;"><strong>{translate key="plugins.generic.acahyarenamedl.displayName"}</strong></h4>
		<p style="margin: 0; font-size: 13px; color: #1e3a8a;">{translate key="plugins.generic.acahyarenamedl.settings.description"}</p>
	</div>

	{fbvFormArea id="renameGeneralSettings"}
		{fbvFormSection list=true}
			{fbvElement type="checkbox" id="enablePlugin" name="enablePlugin" value="1" checked=$enablePlugin label="plugins.generic.acahyarenamedl.settings.enablePlugin"}
			<div class="description" style="margin-left: 20px; font-size: 12px; color: #64748b;">
				{translate key="plugins.generic.acahyarenamedl.settings.enablePluginDescription"}
			</div>
		{/fbvFormSection}

		{fbvFormSection}
			{fbvElement type="select" id="presetPattern" name="presetPattern" from=$presets selected=$presetPattern label="plugins.generic.acahyarenamedl.settings.preset" size=$fbvStyles.size.LARGE}
		{/fbvFormSection}

		{fbvFormSection}
			{fbvElement type="text" id="namingPattern" name="namingPattern" value=$namingPattern label="plugins.generic.acahyarenamedl.settings.namingPattern" size=$fbvStyles.size.LARGE}
			<div class="description" style="margin-top: 6px; font-size: 12px; color: #475569;">
				<strong>{translate key="plugins.generic.acahyarenamedl.settings.namingPatternDescription"}</strong>
				<div style="margin-top: 6px;">
					<span class="acahya-token-badge" data-token="{$submissionId}">{$submissionId}</span>
					<span class="acahya-token-badge" data-token="{$genre}">{$genre}</span>
					<span class="acahya-token-badge" data-token="{$fileId}">{$fileId}</span>
					<span class="acahya-token-badge" data-token="{$submissionFileId}">{$submissionFileId}</span>
					<span class="acahya-token-badge" data-token="{$revision}">{$revision}</span>
					<span class="acahya-token-badge" data-token="{$fileStage}">{$fileStage}</span>
					<span class="acahya-token-badge" data-token="{$date}">{$date}</span>
					<span class="acahya-token-badge" data-token="{$originalName}">{$originalName}</span>
					<span class="acahya-token-badge" data-token="{$authorName}">{$authorName}</span>
					<span class="acahya-token-badge" data-token=".{$ext}">.{$ext}</span>
				</div>
			</div>
		{/fbvFormSection}

		{fbvFormSection}
			{fbvElement type="select" id="replaceSpaces" name="replaceSpaces" from=$spaceOptions selected=$replaceSpaces label="plugins.generic.acahyarenamedl.settings.replaceSpaces" size=$fbvStyles.size.LARGE}
		{/fbvFormSection}

		{fbvFormSection list=true}
			{fbvElement type="checkbox" id="applyToGalleys" name="applyToGalleys" value="1" checked=$applyToGalleys label="plugins.generic.acahyarenamedl.settings.applyToGalleys"}
			<div class="description" style="margin-left: 20px; font-size: 12px; color: #64748b;">
				{translate key="plugins.generic.acahyarenamedl.settings.applyToGalleysDescription"}
			</div>
		{/fbvFormSection}
	{/fbvFormArea}

	<div style="margin-top: 15px; text-align: right;">
		{fbvFormButtons id="acahyaRenameDlSettingsFormSubmit" submitText="common.save"}
	</div>
</form>
