<script language="JavaScript" type="text/JavaScript">
	/*<![CDATA[*/
	$(document).ready(function () {
		$("input[name='shared_mount_point']").change(
			function () {
				if ($("#shared_mount_point_no").is(':checked')) {
					$("#shared_mount_point_domain").hide();
				} else {
					$("#shared_mount_point_domain").show();
				}
			}
		).trigger('change');

		$("input[name='url_forwarding']").change(
			function () {
				if ($("#url_forwarding_no").is(':checked')) {
					$("#tr_url_forwarding_data").hide();
				} else {
					$("#tr_url_forwarding_data").show();
				}
			}
		).trigger('change');
	});
	/*]]>*/
</script>

<form name="add_subdomain_frm" method="post" action="subdomain_add.php">
	<table class="firstColFixed">
		<tr>
			<th colspan="2">{TR_SUBDOMAIN}</th>
		</tr>
		<tr>
			<td><label for="subdomain_name">{TR_SUBDOMAIN_NAME}</label></td>
			<td>
				<input type="text" name="subdomain_name" id="subdomain_name" value="{SUBDOMAIN_NAME}"/>
				<strong>.</strong>
				<label>
					<select name="domain_name">
						<!-- BDP: parent_domain -->
						<option value="{DOMAIN_NAME}"{DOMAIN_NAME_SELECTED}>{DOMAIN_NAME_UNICODE}</option>
						<!-- EDP: parent_domain -->
					</select>
				</label>
			</td>
		</tr>
		<tr>
			<td>{TR_SHARED_MOUNT_POINT}<span class="icon i_help" title="{TR_SHARED_MOUNT_POINT_TOOLTIP}"></span></td>
			<td>
				<div class="radio">
					<input type="radio" name="shared_mount_point" id="shared_mount_point_yes" value="yes"{SHARED_MOUNT_POINT_YES}/>
					<label for="shared_mount_point_yes">{TR_YES}</label>
					<input type="radio" name="shared_mount_point" id="shared_mount_point_no" value="no"{SHARED_MOUNT_POINT_NO}/>
					<label for="shared_mount_point_no">{TR_NO}</label>
					<label for="shared_mount_point_domain">
						<select name="shared_mount_point_domain" id="shared_mount_point_domain" style="vertical-align: middle">
							<!-- BDP: shared_mount_point_domain -->
							<option value="{DOMAIN_NAME}"{SHARED_MOUNT_POINT_DOMAIN_SELECTED}>{DOMAIN_NAME_UNICODE}</option>
							<!-- EDP: shared_mount_point_domain -->
						</select>
					</label>
				</div>
			</td>
		</tr>
		<tr>
			<td>{TR_URL_FORWARDING} <span class="icon i_help" title="{TR_URL_FORWARDING_TOOLTIP}"></span></td>
			<td>
				<div class="radio">
					<input type="radio" name="url_forwarding" id="url_forwarding_yes"{FORWARD_URL_YES} value="yes"/>
					<label for="url_forwarding_yes">{TR_YES}</label>
					<input type="radio" name="url_forwarding" id="url_forwarding_no"{FORWARD_URL_NO} value="no"/>
					<label for="url_forwarding_no">{TR_NO}</label>
				</div>
			</td>
		</tr>
		<tr id="tr_url_forwarding_data">
			<td>{TR_FORWARD_TO_URL}</td>
			<td>
				<label for="forward_url_scheme">
					<select name="forward_url_scheme" id="forward_url_scheme">
						<option value="http://"{HTTP_YES}>{TR_HTTP}</option>
						<option value="https://"{HTTPS_YES}>{TR_HTTPS}</option>
						<option value="ftp://"{FTP_YES}>{TR_FTP}</option>
					</select>
				</label>
				<label>
					<input name="forward_url" type="text" id="forward_url" value="{FORWARD_URL}"/>
				</label>
			</td>
		</tr>
	</table>
	<div class="buttons">
		<input name="Submit" type="submit" value="{TR_ADD}"/>
		<button><a href="domains_manage.php">{TR_CANCEL}</a></button>
	</div>
</form>
