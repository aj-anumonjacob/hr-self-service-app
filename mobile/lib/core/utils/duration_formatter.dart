/// Shared human-readable duration formatter for Android and iOS.
///
/// Output expectations:
///   Less than 60 seconds -> "Less than 1 minute"
///   60 seconds           -> "1 minute"
///   1,500 seconds        -> "25 minutes"
///   3,600 seconds        -> "1 hour"
///   3,660 seconds        -> "1 hour 1 minute"
///   6,660 seconds        -> "1 hour 51 minutes"
///   7,200 seconds        -> "2 hours"
String formatDuration(int totalSeconds) {
  if (totalSeconds < 60) {
    return 'Less than 1 minute';
  }
  if (totalSeconds == 60) {
    return '1 minute';
  }

  final totalMinutes = totalSeconds ~/ 60;
  final hours = totalMinutes ~/ 60;
  final minutes = totalMinutes % 60;

  if (hours == 0) {
    return minutes == 1 ? '1 minute' : '$minutes minutes';
  }

  final String hrsStr = hours == 1 ? '1 hour' : '$hours hours';
  if (minutes == 0) {
    return hrsStr;
  }

  final String minsStr = minutes == 1 ? '1 minute' : '$minutes minutes';
  return '$hrsStr $minsStr';
}
