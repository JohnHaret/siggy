
@extends('layouts.public',['layoutMode' => 'blank', 'title' => 'complete sso', 'selectedTab'=>'login'])

@section('content')
<style type='text/css'>
  .login-or {
    position: relative;
    font-size: 18px;
    color: #aaa;
    margin-top: 10px;
            margin-bottom: 10px;
    padding-top: 10px;
    padding-bottom: 10px;
  }
  .span-or {
    display: block;
    position: absolute;
    left: 50%;
    top: -2px;
    margin-left: -25px;
    background-color: #303030;
    width: 50px;
    text-align: center;
  }
  .hr-or {
    background-color: #cdcdcd;
    height: 1px;
    margin-top: 0px !important;
    margin-bottom: 0px !important;
  }
</style>
<div class="container">
	<div class="row colored">
		<div class="col-sm-8 col-sm-offset-3 col-md-6 col-md-offset-3">
			<div class="panel panel-default">
				<div class="panel-heading">
					<strong> siggy account required</strong>
				</div>
				<div class="panel-body">
					<div class="alert alert-info" role="alert"
						You have successfully logged into the EVE SSO. <br />
						Now you must connect your EVE SSO Character login to a siggy account.<br />
						Once connected, future SSO logins will not require this step.
					</div>
					<hr />
					<h4>Login</h4>
					{!! Form::open(['url' => 'account/login']) !!}
						
						@if($errors->any())
						<div class="alert alert-danger">
						{{$errors->first()}}
						</div>
						@endif
						<div class="row">
							<div class="col-sm-12 col-md-10  col-md-offset-1 ">
								<div class="form-group">
									<div class="input-group">
										<span class="input-group-addon">
											<i class="fa fa-user" aria-hidden="true"></i>
										</span> 
										{{ Form::text('username',null, ['class' => 'form-control', 'placeholder' => 'Username', 'autofocus' => 'autofocus']) }}
									</div>
								</div>
								<div class="form-group">
									<div class="input-group">
										<span class="input-group-addon">
											<i class="fa fa-lock" aria-hidden="true"></i>
										</span>
										{{ Form::password('password',['class' => 'form-control', 'placeholder' => 'Password']) }}
									</div>
								</div>
								<div class="checkbox">
									<label class="pull-right">
										{{ Form::checkbox('remember', '1', true) }} Remember me?
									</label>
								</div>
								<div class="form-group">
									<input type="submit" class="btn btn-lg btn-primary btn-block" value="Sign in">
								</div>
							</div>
						</div>
					{!! Form::close() !!}

					<span class='help-block text-centered'>Lost or forgot password? <a href='{{url('account/forgotPassword')}}'>Click here</a></span>

					<div class="login-or">
							<hr class="hr-or">
							<span class="span-or">or</span>
					</div>

					<div class="text-center">
						<a href="{{url('account/register')}}" class="btn btn-lg btn-primary">Create a siggy account</a>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>
@endsection