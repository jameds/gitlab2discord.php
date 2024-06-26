<?php
/*
Copyright 2023, James R.
All rights reserved.

Redistribution of source code, with or without modification, is permitted
provided that it retains the above copyright notice, this condition and the
following disclaimer.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR
ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
(INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND
ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
(INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
*/

# https://discord.com/developers/docs/resources/channel#embed-object-embed-limits
define ('LIMITS', [
	'title' => 256,
	'description' => 4096,
	'footer' => [
		'text' => 2048
	],
	'author' => [
		'name' => 256,
	]
]);

define ('TRUNCATED_WARNING', <<<EOF
*Part of this content was truncated due to Discord's message limits.*
EOF);

function truncate (&$obj, &$rem, $limits) {
	$status = 0;

	foreach ($limits as $k => $limit)
	{
		if (isset ($obj[$k]))
		{
			# If this were done before checking isset,
			# the element would be implicitly created.
			$v = &$obj[$k];

			if (is_array($limit))
				truncate($v, $rem, $limit);
			else
			{
				$n = min($limit, $rem);

				if (strlen($v) > $n)
				{
					$v = substr($v, 0, $n);
					$status |= 1;
				}

				$rem -= strlen($v);
			}
		}
	}

	return $status;
}

function discord ($event, $embeds) {
	$u = "https://discordapp.com/api/webhooks/{$_GET['id']}/{$_GET['token']}";
	$h = curl_init("$u?wait=true");

	$user = $event->user_name ?? $event->user->name;
	$avatar = $event->user_avatar ?? $event->user->avatar_url;

	if (count($embeds) > 10)
		array_splice($embeds, 10);

	if (isset ($_GET['multiuse']))
	{
		$embeds[sizeof($embeds) - 1]['footer'] = [
			'text' => $event->project->path_with_namespace,
			'icon_url' => $_GET['custom_avatar'] ?? $event->project->avatar_url,
		];
	}

	$embeds[0]['author'] = [
		'name' => "$user via {$event->project->name}",
		'url' => $event->project->web_url,
		'icon_url' => $avatar,
	];

	# 1. Embed fields have individual limits.
	# 2. The embed overall has a limit.
	# 3. It is an error for an embed field to be set to an
	#    empty string or pure whitespace.

	$rem = 6000 - strlen(TRUNCATED_WARNING);
	$truncated = 0;

	foreach ($embeds as &$embed)
	{
		$truncated |= truncate($embed, $rem, LIMITS);
	}

	if ($truncated)
	{
		$embeds[sizeof($embeds) - 1]['footer'] = [
			'text' => TRUNCATED_WARNING,
		];
	}

	$json = ['embeds' => $embeds];

	if (isset ($_GET['use_project_avatar']))
		$json['avatar_url'] = $event->project->avatar_url;
	elseif (isset ($_GET['custom_avatar']))
		$json['avatar_url'] = $_GET['custom_avatar'];

	if (isset ($_GET['display_name']))
		$json['username'] = $_GET['display_name'];

	curl_setopt_array($h, [
		CURLOPT_CUSTOMREQUEST => 'POST',
		CURLOPT_POSTFIELDS => json_encode($json),
		CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
		CURLOPT_RETURNTRANSFER => TRUE,
	]);

	$body = curl_exec($h);
	$code = curl_getinfo($h, CURLINFO_RESPONSE_CODE);

	if ($code >= 400)
	{
		echo "Discord said $code...\n$body";
		http_response_code(500);
	}
}

# returns $n $noun, or pluralises $noun if $n > 1
function plural ($n, $noun) {
	return "$n " . ($n > 1 ? "{$noun}s" : $noun);
}

# Squashes repeated whitespace. Also expands issue tokens.
function markup ($text, $event) {
	$here = $event->project->web_url;
	$root = substr($here, 0, -1 - strlen($event->project->path_with_namespace));
	$up = substr($here, 0, -1 - strlen($event->project->name));

	return preg_replace([
		'/ {2}/',
		'/\b([\w_-]+\/[\w_-]+)#(\d+)/',
		'/\b([\w_-]+\/[\w_-]+)!(\d+)/',
		'/(?<![\/\w_-])([\w_-]+)#(\d+)/',
		'/(?<![\/\w_-])([\w_-]+)!(\d+)/',
		'/(?<!\w)#(\d+)/',
		'/(?<!\w)!(\d+)/',
		'/\[((?:(?!\]).)+)\]\(\/(uploads\/(?:(?!\)).)+)\)/',
	], [
		"\u{00a0}\u{00a0}", # use a nbsp to prevent squashing into a single character
		"[\$0]($root/\$1/issues/\$2)",
		"[\$0]($root/\$1/merge_requests/\$2)",
		"[\$0]($up/\$1/issues/\$2)",
		"[\$0]($up/\$1/merge_request/\$2)",
		"[\$0]($here/issues/\$1)",
		"[\$0]($here/merge_requests/\$1)",
		"[\$1]($here/\$2)",
	], $text);
}

# Splits text into multiple embeds of description
# and/or image between GitLab markdown images.
function split_images ($text) {
	$embeds = [];
	$from = 0;

	$matches = preg_match_all('/!\[(?:(?!\]).)*\]\(((?:(?!\)).)+)\)/', $text,
		$captures, PREG_OFFSET_CAPTURE);

	for ($i = 0; $i < $matches; ++$i)
	{
		$cap = $captures[0][$i];
		$ofs = $cap[1] - $from;
		$embeds[$i] = [
			'description' => substr($text, $from, $ofs),
			'image' => ['url' => $captures[1][$i][0]],
		];
		$from += $ofs + strlen($cap[0]);
	}

	if ($from < strlen($text))
	{
		$embeds[$i] = ['description' => substr($text, $from)];
	}

	return $embeds;
}

# Makes a commit list in markup :)
function commit_markup ($event) {
	$s = '';

	foreach ($event->commits as $commit) {
		$abbrev = substr($commit->id, 0, 7);
		$body = markup(trim($commit->message), $event);
		$s .= "\n\n([`$abbrev`]({$commit->url})) $body" .
			" - *{$commit->author->name} <{$commit->author->email}>*";
	}

	return substr($s, 2);
}

# Cuts a substring off the start of a string
function cut ($prefix, $from) {
	return substr($from, strlen($prefix));
}

# Returns past tense of a verb.
function past_tense ($verb) {
	$suf = substr($verb, -1);
	return $suf !== 'd' ? $verb . ($suf === 'e' ? 'd' : 'ed') : $verb;
}

# Prepends the state of the issue if it is no longer open.
function posthumous ($issue, $issue_attributes) {
	return $issue_attributes->state === 'opened' ?
		$issue : "{$issue_attributes->state} $issue";
}

# Prepends confidential issue annotation.
function confidential ($issue, $issue_attributes) {
	return $issue_attributes->confidential ?
		"Confidential :shushing_face: $issue" : $issue;
}

function normalize_lf ($text) {
	return str_replace("\r\n", "\n", $text);
}

function text_changed ($change = NULL) {
	return isset ($change) &&
		normalize_lf($change->previous) !==
		normalize_lf($change->current);
}

function issue_hook ($issue, $event) {
	if ($event->object_attributes->action === 'update')
	{
		if (text_changed($event->changes->description))
			$changed = 'Changed the description of';
		elseif (isset ($event->changes->title))
			$changed = 'Changed the title of';
		elseif (
			isset ($event->object_attributes->oldrev) &&
			$event->object_attributes->source_project_id !== $event->project->id
		){
			$changed = 'Made commits to'; # update for external merge request
		}
		else
		{
			http_response_code(202);
			die('But the request was ignored.');
		}

		$issue = posthumous($issue, $event->object_attributes);
	}
	else
		$changed = ucfirst(past_tense($event->object_attributes->action));

	$embed = [
		'title' => "$changed $issue{$event->object_attributes->iid}",
		'url' => $event->object_attributes->url,
	];

	$title = "**{$event->object_attributes->title}**";

	if (
		$event->object_attributes->action === 'open' ||
		isset ($event->changes->description)
	){
		$embeds = split_images(markup
			($title . "\n" .  $event->object_attributes->description, $event));
		$embeds[0] = array_merge($embeds[0], $embed);
	}
	else
	{
		$embed['description'] = $title;
		$embeds = [$embed];
	}

	return $embeds;
}

if (
	$_SERVER['REQUEST_METHOD'] === 'POST' &&
	$_SERVER['CONTENT_TYPE'] === 'application/json' &&
	isset (
		$_GET['id'],
		$_GET['token'],
		$_SERVER['HTTP_X_GITLAB_EVENT']
	)
){
	$valid_hooks = [
		'Push Hook',
		'Tag Push Hook',
		'Issue Hook',
		'Confidential Issue Hook',
		'Note Hook',
		'Confidential Note Hook',
		'Merge Request Hook',
		'Wiki Page Hook',
	];

	$hook = $_SERVER['HTTP_X_GITLAB_EVENT'];

	if (in_array($hook, $valid_hooks))
	{
		$event = json_decode(file_get_contents('php://input'));

		switch ($hook)
		{
		case 'Push Hook':
			# a deleted branch checks out to null sha1
			# (event->after is the full sha1)
			if (is_null($event->checkout_sha))
				$embed = ['title' => 'Deleted branch'];
			else
			{
				$embed =
					['url' => "{$event->project->web_url}/commits/{$event->after}"];

				if (empty($event->commits))
					$embed['title'] = 'Pushed new branch';
				else
				{
					$count = plural($event->total_commits_count, 'commit');
					$embed['title'] = "Pushed $count to";

					if (count($event->commits) === $event->total_commits_count)
						$embed['description'] = commit_markup($event);
				}
			}

			$branch = cut('refs/heads/', $event->ref);
			$embed['title'] .= " `$branch`";
			discord($event, [$embed]);
			break;

		case 'Tag Push Hook':
			$tag = cut('refs/tags/', $event->ref);

			if (is_null($event->checkout_sha))
				$embed = ['title' => 'Deleted tag'];
			else
			{
				$embed = [
					'title' => 'Pushed tag',
					'description' => $event->message,
					'url' => "{$event->project->web_url}/tags/$tag"
				];
			}

			$embed['title'] .= " `$tag`";
			discord($event, [$embed]);
			break;

		case 'Issue Hook':
		case 'Confidential Issue Hook':
			discord($event, issue_hook(confidential('Issue #',
				$event->object_attributes), $event));
			break;

		case 'Merge Request Hook':
			discord($event, issue_hook('Merge Request !', $event));
			break;

		case 'Note Hook':
		case 'Confidential Note Hook':
			switch ($event->object_attributes->noteable_type)
			{
			case 'Issue':
				$object = posthumous(confidential('Issue #' .
					$event->issue->iid, $event->issue), $event->issue);
				$title = $event->issue->title;
				break;

			case 'MergeRequest':
				$object = posthumous('Merge Request !' .
					$event->merge_request->iid, $event->merge_request);
				$title = $event->merge_request->title;
				break;

			case 'Commit':
				$id = substr($event->commit->id, 0, 7);
				$object = "commit `$id`";
				$title = strtok($event->commit->message, "\n");
				break;
			}

			if ($event->object_attributes->type === 'DiffNote')
			{
				$object = <<<EOT
`{$event->object_attributes->position->new_path}` from $object
EOT;
			}

			$action = 'Commented';

			if ($event->object_attributes->action === 'update')
				$action = 'Edited a comment';

			$embed = [
				'title' => "$action on $object",
				'url' => $event->object_attributes->url,
			];

			$embeds = split_images(markup
				("**$title**\n" .  $event->object_attributes->note, $event));
			$embeds[0] = array_merge($embeds[0], $embed);

			discord($event, $embeds);
			break;

		case 'Wiki Page Hook':
			$action = [
				'create' => 'Added',
				'update' => 'Updated',
				'delete' => 'Removed',
			][$event->object_attributes->action];

			$embed = [
				'title' => "$action wiki page {$event->object_attributes->slug}",
				'url' => $event->object_attributes->url,
			];

			$m = $event->object_attributes->message;

			if (
				$m !== "Create {$event->object_attributes->slug}" &&
				$m !== "Update {$event->object_attributes->title}"
			){
				$embed['description'] = markup($m, $event);
			}

			discord($event, [$embed]);
			break;
		}
	}
	else
		http_response_code(400);
}
else
	http_response_code(400);

// vim: tw=80
?>
