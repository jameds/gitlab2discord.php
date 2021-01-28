<?php
/*
Copyright 2021, James R.
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

function discord ($event, $embed) {
	$u = "https://discordapp.com/api/webhooks/{$_GET['id']}/{$_GET['token']}";
	$h = curl_init($u);

	$json = [
		'username' => "{$event->project->name} Repository Update",
		'avatar_url' => $event->project->avatar_url,

		'embeds' => [array_merge($embed, [
			'author' => [
				'name' => "{$event->project->name} via {$event->user_name}",
				'url' => $event->project->web_url,
				'icon_url' => $event->user_avatar,
			],
			'footer' => [
				'text' => $event->project->path_with_namespace,
				'icon_url' => $event->project->avatar_url,
			],
		])],
	];

	curl_setopt_array($h, [
		CURLOPT_CUSTOMREQUEST => 'POST',
		CURLOPT_POSTFIELDS => json_encode($json),
		CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
		CURLOPT_RETURNTRANSFER => TRUE,
	]);

	http_response_code(curl_exec($h));
}

# returns $n $noun, or pluralises $noun if $n > 1
function plural ($n, $noun) {
	return "$n " . ($n > 1 ? "{$noun}s" : $noun);
}

# Squashes repeated whitespace. Also expands issue tokens.
function markup ($text, $event) {
	$here = $event->project->web_url;
	$root = substr($here, 0, -(strlen($event->project->path_with_namespace)));
	$up = substr($here, 0, -1 - strlen($event->project->namespace));

	return preg_replace([
		'/ +/',
		'/\b(\w+\/\w+)#(\d+)/',
		'/\b(\w+\/\w+)!(\w+)/',
		'/\b(\w+)#(\d+)/',
		'/\b(\w+)!(\w+)/',
		'/(?:[^!-~])#(\d+)/',
		'/(?:[^!-~])!(\d+)/',
	], [
		' ',
		"[\$0]($root/\$1/issues/\$2)",
		"[\$0]($root/\$1/merge_requests/\$2)",
		"[\$0]($up/\$1/issues/\$2)",
		"[\$0]($up/\$1/merge_request/\$2)",
		"[\$0]($here/issues/\$1)",
		"[\$0]($here/merge_requests/\$1)",
	], $text);
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
		#'Tag Push Hook',
		#'Issue Hook',
		#'Note Hook',
		#'Merge Request Hook',
		#'Wiki Page Hook',
	];

	$hook = $_SERVER['HTTP_X_GITLAB_EVENT'];

	if (in_array($hook, $valid_hooks))
	{
		$event = json_decode(file_get_contents('php://input'));

		switch ($hook)
		{
		case 'Push Hook':
			$embed = [];

			# a deleted branch checks out to null sha1
			# (event->after is the full sha1)
			if (is_null($event->checkout_sha))
				$embed = ['title' => 'Deleted branch'];
			else
			{
				$embed = [
					'url' => "{$event->project->web_url}/commits/{$event->after}"
				];

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

			$branch = substr($event->ref, strlen('refs/heads/'));
			$embed['title'] .= " `$branch`";

			discord($event, $embed);
			break;
		}
	}
	else
		http_response_code(400);
}
else
	http_response_code(400);

?>
