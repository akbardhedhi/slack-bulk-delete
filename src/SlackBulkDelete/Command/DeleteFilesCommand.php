<?php

namespace SlackBulkDelete\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7;
use GuzzleHttp\Psr7\Request;

class DeleteFilesCommand extends Command
{

  const CONCURRENT_REQUESTS = 10;

  /**
   * @var Client Guzzle Client
   */
  protected $client;

  /**
   * Setup command
   */
  protected function configure()
  {
    $this
        ->setName('slack:deletefiles')
        ->setDescription('Delete slack files uploaded before a certain number of days')
        ->addArgument('days', InputArgument::OPTIONAL, 30);

    $this->client = new Client();
  }

  /**
   * Command logic.
   *
   * @param InputInterface $input
   * @param OutputInterface $output
   * @return void
   */
  protected function execute(InputInterface $input, OutputInterface $output)
  {
    $days = $input->getArgument('days');

    $errors = [];

    if (!ctype_digit($days)) {
      $errors[] = 'Argument must be a positive integer';
    }

    if (!getenv('SLACK_AUTH_TOKEN')) {
      $errors[] = 'You must set the SLACK_AUTH_TOKEN environment variable';
    }

    if (count($errors) > 0) {
      $output->writeln('<error>Please address the following issues</error>');

      foreach ($errors as $message) {
        $output->writeln("<comment>- $message</comment>");
      }

      return;
    }

    $output->writeln("<info>Retrieving list of files older then $days old</info>");
    $files = $this->getFilesForDeletion($days);
    $no = count($files);

    if ($no > 0) {
      $output->writeln("<comment>Deleting $no files</comment>");
      $this->deleteFiles($files, $output);
    }
    else {
      $output->writeln("<comment>Nothing to delete</comment>");
    }
  }

  /**
   * Get list of files for deletion older then n days.
   *
   * @param $days
   *   Number of days.
   * @return array
   *   File ID's.
   */
  protected function getFilesForDeletion($days)
  {
    $response = $this->client->post('https://slack.com/api/files.list', [
      'form_params' => [
        'token' => getenv('SLACK_AUTH_TOKEN'),
        'ts_to' => strtotime("-$days days"),
      ]
    ]);

    $response = json_decode($response->getBody(), TRUE);
    return array_column($response['files'], 'id');
  }


  /**
   * Concurrently delete the files.
   *
   * @param array $files
   *   Array of file ID's.
   * @param OutputInterface $output
   *   Console output interface.
   */
  protected function deleteFiles(array $files, OutputInterface $output) {
    $requests = function ($files) {
      $uri = "https://slack.com/api/files.delete";
      foreach ($files as $file_id) {
        $req = new Request('POST', $uri);

        $modify['body'] = Psr7\stream_for(http_build_query([
          'file'  => $file_id,
          'token' => getenv('SLACK_AUTH_TOKEN'),
        ]));
        $modify['set_headers']['Content-Type'] = 'application/x-www-form-urlencoded';
        $modify['set_headers']['Cache-Control'] = 'no-cache';

        $request = Psr7\modify_request($req, $modify);
        yield $request;
      }
    };

    $pool = new Pool($this->client, $requests($files), [
      'concurrency' => self::CONCURRENT_REQUESTS,
      'fulfilled' => function ($response, $index) use ($output) {
          $output->writeln("<info>File deleted</info>");
       },
      'rejected' => function ($reason, $index) use ($output) {
          $output->writeln("<error>Error deleting file $reason</error>");
      },
    ]);

    $promise = $pool->promise();
    $promise->wait();
  }

}