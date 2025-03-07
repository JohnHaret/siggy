<!DOCTYPE html>
<html lang="en">
 <head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Server Error</title>

    <!-- Le styles -->
    <link href="{{asset('bootstrap3/css/bootstrap.min.css')}}" rel="stylesheet">
    <link href="{{asset('css/frontend.css')}}" rel="stylesheet">
	<script src="//ajax.googleapis.com/ajax/libs/jquery/1.11.0/jquery.min.js"></script>
    <script src="{{asset('bootstrap3/js/bootstrap.min.js')}}"></script>



    <!-- Le HTML5 shim, for IE6-8 support of HTML5 elements -->
    <!-- HTML5 shim and Respond.js IE8 support of HTML5 elements and media queries -->
    <!--[if lt IE 9]>
      <script src="https://oss.maxcdn.com/html5shiv/3.7.2/html5shiv.min.js"></script>
      <script src="https://oss.maxcdn.com/respond/1.4.2/respond.min.js"></script>
    <![endif]-->

    <!-- Le fav and touch icons -->
    <link rel="shortcut icon" href="{{asset('favicon.ico')}}">
	<script type="text/javascript">
	$(document).ready(function() {
		$('.dropdown-toggle').dropdown()
	});
	</script>
</head>
<body>
	<div class="navbar navbar-default navbar navbar-fixed-top">
		<div class="navbar-header">
			<button type="button" class="navbar-toggle" data-toggle="collapse" data-target=".navbar-responsive-collapse">
				<span class="icon-bar"></span>
				<span class="icon-bar"></span>
				<span class="icon-bar"></span>
			</button>
			<a class="navbar-brand" href="{{url('/')}}">siggy</a>
		</div>
	</div>
	<div class="container">
		<div class="row">
			<h1>The wormhole has collapsed!</h1>
			<p>
			Sorry but an server error has occurred. Please try again, if problem persists, contact <b>Jack Tronic</b> ingame for support.
			</p>

			<a href="{{url('/')}}" class="btn btn-primary">Back to siggy's home</a>
		</div>
	</div>
</body>
</html>

