const funcs = require('../functions/functions')
const compiledTemplates = require('../compiledTemplates/compiledTemplates')
const socketEvents = require('../events/events')
const { Sequelize, Op, DataTypes } = require("sequelize");
const striptags = require('striptags');
const moment = require("moment")

const LoadmoreController = async (ctx, data, io,socket,callback) => {
  let fromUser = await ctx.wo_users.findOne({
      where: {
          user_id: {
              [Op.eq]: ctx.userHashUserId[data.from_id]
          }
      }
  })
  let after_message_id = await ctx.wo_messages.findOne({
      where: {
          [Op.or]: [
              {
                  from_id: {
                      [Op.eq]: ctx.userHashUserId[data.from_id]
                  },
                  to_id: {
                      [Op.eq]: parseInt(data.to_id)
                  }
              },
              {
                  from_id: {
                      [Op.eq]: parseInt(data.to_id)
                  },
                  to_id: {
                      [Op.eq]: ctx.userHashUserId[data.from_id]
                  }
              }
          ]
      }
  })
  let messages = await ctx.wo_messages.findAll({
      limit: 15,
      where: {
          id: {
              [Op.gte]: after_message_id.id,
              [Op.lt]: data.before_message_id
          },
          [Op.or]: [
              {
                  from_id: {
                      [Op.eq]: ctx.userHashUserId[data.from_id]
                  },
                  to_id: {
                      [Op.eq]: parseInt(data.to_id)
                  }
              },
              {
                  from_id: {
                      [Op.eq]: parseInt(data.to_id)
                  },
                  to_id: {
                      [Op.eq]: ctx.userHashUserId[data.from_id]
                  }
              }
          ]
      },
      order: [['id', 'DESC']]
  })
  let html = ""
  for (let message_index = messages.length - 1; message_index >= 0; message_index--) {
      let message = messages[message_index]
      if (message.media && message.media != "") {
          let d = { ...data }
          d.mediaId = message.id;
          if (message.from_id === ctx.userHashUserId[data.from_id]) {
              html += await compiledTemplates.chatListOwnerTrueWithMedia(ctx, d, fromUser, message.id, data.color, data.isSticker)
          }
          else {
              html += await compiledTemplates.chatListOwnerFalseWithMedia(ctx, d, fromUser, message.id, true, data.isSticker)
          }
      } else {
          data.have_story = false;
          data.story = {thumbnail: '',
                       id: 0,
                       title: ''};
          if (message.story_id && message.story_id > 0) {
              var story = await ctx.wo_userstory.findOne({
                                  where: {
                                      id: message.story_id
                                  }
                              })
              if (story && story.id) {
                  data.have_story = true;
                  story.thumbnail = await funcs.Wo_GetMedia(ctx, story.thumbnail);
              }
              data.story = story;
          }

          let msg = message.text || "";
          if (!message.text) {
              message.text = ""
          }
          let hasHTML = message.text.split(" ").includes("<i")
          // ({ msg, hasHTML } = funcs.Wo_Emo(message.text))
          // message.text = msg
          let link_regex = new RegExp('(http\:\/\/|https\:\/\/|www\.)([^\ ]+)', 'gi');
          let mention_regex = new RegExp('@([A-Za-z0-9_]+)', 'gi');
          // let hashtag_regex = new RegExp('#([^`~!@$%^&*\#()\-+=\\|\/\.,<>?\'\":;{}\[\]* ]+)', 'gi');

          let linkSearch = msg.match(link_regex)
          if (linkSearch && linkSearch.length > 0) {
              hasHTML = true;
              for (let linkSearchOne of linkSearch) {
                  let matchUrl = striptags(linkSearchOne)
                  let syntax = '[a]' + escape(matchUrl) + '[/a]'
                  message.text = message.text.replace(link_regex, syntax)
              }
          }
          let mentionSearch = msg.match(mention_regex)
          if (mentionSearch && mentionSearch.length > 0) {
              hasHTML = true;
              for (let mentionSearchOne of mentionSearch) {
                  let mention = await ctx.wo_users.findOne({
                      where: {
                          username: mentionSearchOne.substr(1, mentionSearchOne.length)
                      }
                  })
                  if (mention) {
                      let match_replace = '@[' + mention['user_id'] + ']';
                      message.text = message.text.replace(mention_regex, match_replace)
                  }
              }
          }
          let hashTagSearch = msg.match(/#([^`~!@$%^&*\#()\-+=\\|\/\.,<>?\'\":;{}\[\]* ]+)/gi)
          if (hashTagSearch && hashTagSearch.length > 0) {
              hasHTML = true
              for (let hashTagSearchOne of hashTagSearch) {
                  let hashdata = await funcs.Wo_GetHashtag(ctx, hashTagSearchOne.substr(1))
                  let replaceString = '#[' + hashdata['id'] + ']';
                  message.text = message.text.replace(/#([^`~!@$%^&*\#()\-+=\\|\/\.,<>?\'\":;{}\[\]* ]+)/gi, replaceString)
                  await ctx.wo_hashtags.update({
                      last_trend_time: Math.floor(Date.now() / 1000),
                      trend_use_num: hashdata["trend_use_num"] + 1
                  },
                      {
                          where: {
                              id: hashdata['id']
                          }
                      })
              }
          }
          let sendable_message = await funcs.Wo_Markup(ctx, message.text);
          if (message.from_id === ctx.userHashUserId[data.from_id]) {
              html += await compiledTemplates.chatListOwnerTrue(ctx, data, fromUser, message.id, true, sendable_message, data.color)
          } else {
              html += await compiledTemplates.chatListOwnerFalse(ctx, data, fromUser, message.id, true, sendable_message)
          }
      }
  }
  callback({
      status: 200,
      html: html
  })
};

module.exports = { LoadmoreController };